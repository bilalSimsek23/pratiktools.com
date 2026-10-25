<?php

namespace App\Http\Controllers\Panel;

use App\Automation\AutomationDefinitionError;
use App\Automation\AutomationMetrics;
use App\Automation\Automations;
use App\Automation\AutomationTemplates;
use App\Automation\DryRun;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Panel\AutomationPresenter;
use App\Panel\SegmentForm;
use App\Segments\SegmentRuleError;
use App\Segments\Segments;
use App\Tenancy\Permission;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class SegmentAutomationController extends Controller
{
    use PanelHelpers;

    public function segments(): View
    {
        $this->authorizePanel(Permission::CampaignsView);
        $segments = DB::table('segments')->orderBy('name')->get()->map(function ($s) {
            $s->description = SegmentForm::describe((array) json_decode($s->definition, true));

            return $s;
        });

        return view('panel.segments', ['segments' => $segments, 'fields' => SegmentForm::FIELDS, 'operators' => SegmentForm::OPERATORS,
            'preview' => session('segment_preview'), 'canManage' => $this->can(Permission::CampaignsManage)]);
    }

    public function previewSegment(Request $request, SegmentForm $form, Segments $segments): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsView);
        try {
            $definition = $form->toDefinition($request->all());
            $preview = $segments->preview($definition) + ['description' => SegmentForm::describe($definition)];
        } catch (SegmentRuleError $e) {
            return $this->failed("Kural geçersiz: {$e->getMessage()}");
        }

        return back()->withInput()->with('segment_preview', $preview);
    }

    public function storeSegment(Request $request, SegmentForm $form, Segments $segments): RedirectResponse
    {
        $this->authorizePanel(Permission::CampaignsManage);
        $name = (string) $request->validate(['name' => 'required|string|max:120'])['name'];
        try {
            $segments->create($name, 'dynamic', $form->toDefinition($request->all()), $this->actor());
        } catch (SegmentRuleError $e) {
            return $this->failed("Kural geçersiz: {$e->getMessage()}");
        } catch (QueryException) {
            return $this->failed('Bu isimde bir segment zaten var.');
        }

        return $this->done('Segment kaydedildi.');
    }

    public function automations(): View
    {
        $this->authorizePanel(Permission::AutomationsView);
        $rows = DB::table('automations as a')->leftJoin('automation_versions as v', fn ($j) => $j->on('v.id', '=', 'a.published_version_id')->on('v.tenant_id', '=', 'a.tenant_id'))
            ->orderBy('a.name')->get(['a.*', 'v.trigger', 'v.version as published_version',
                DB::raw("(SELECT count(*) FROM automation_runs r WHERE r.tenant_id = a.tenant_id AND r.automation_id = a.id AND r.status = 'waiting') AS waiting")]);
        $rows->each(function ($a) {
            $latest = DB::table('automation_versions')->where('automation_id', $a->id)->orderByDesc('version')->first(['trigger', 'version']);
            $a->trigger_text = AutomationPresenter::trigger((array) json_decode((string) ($a->trigger ?? $latest?->trigger), true));
            $a->latest_version = $latest?->version;
        });

        return view('panel.automations.index', ['rows' => $rows, 'canManage' => $this->can(Permission::AutomationsManage)]);
    }

    public function automation(string $automation, AutomationMetrics $metrics): View
    {
        $this->authorizePanel(Permission::AutomationsView);
        $a = DB::table('automations')->where('id', $automation)->first() ?? abort(404);
        $versions = DB::table('automation_versions')->where('automation_id', $a->id)->orderByDesc('version')->get();
        $current = $versions->firstWhere('id', $a->published_version_id) ?? $versions->first();
        $settings = (array) json_decode((string) $current->settings, true);

        return view('panel.automations.show', [
            'a' => $a,
            'versions' => $versions,
            'current' => $current,
            'trigger' => AutomationPresenter::trigger((array) json_decode($current->trigger, true)),
            'steps' => AutomationPresenter::steps((array) json_decode($current->steps, true)),
            'audience' => ($settings['message_type'] ?? 'marketing') === 'marketing' ? 'Pazarlama izni (İYS ONAY) uygun müşteriler' : 'İşlemsel: kendi işlemiyle ilgili müşteri',
            'runs' => DB::table('automation_runs')->where('automation_id', $a->id)->orderByDesc('started_at')->limit(20)
                ->get(['id', 'status', 'trigger_type', 'current_step', 'resume_at', 'exit_reason', 'started_at', 'completed_at']),
            'metrics' => $metrics->report($a->id),
            'dryRun' => session('automation_dry_run'),
            'canManage' => $this->can(Permission::AutomationsManage),
        ]);
    }

    public function storeAutomation(Request $request, Automations $automations): RedirectResponse
    {
        $this->authorizePanel(Permission::AutomationsManage);
        $data = $request->validate(['template' => 'required|in:abandoned_cart,winback', 'name' => 'required|string|max:120',
            'delay_hours' => 'nullable|integer|min:1|max:720', 'days' => 'nullable|integer|min:1|max:3650', 'automation' => 'nullable|uuid']);
        $definition = AutomationTemplates::make($data['template'], ['delay_minutes' => (int) ($data['delay_hours'] ?? 2) * 60, 'days' => (int) ($data['days'] ?? 90)]);
        try {
            if (isset($data['automation'])) {
                $automations->change($data['automation'], $definition['trigger'], $definition['steps'], $definition['settings'], $this->actor());

                return $this->done('Yeni versiyon taslak olarak kaydedildi; yayınlanana kadar mevcut sürüm çalışır.');
            }
            $id = $automations->create($data['name'], $definition['trigger'], $definition['steps'], $definition['settings'], $this->actor(), $data['template']);
        } catch (AutomationDefinitionError $e) {
            return $this->failed($e->getMessage());
        } catch (QueryException) {
            return $this->failed('Bu isimde bir otomasyon zaten var.');
        }

        return redirect()->route('panel.automations.show', $id)->with('status', 'Taslak otomasyon oluşturuldu.');
    }

    public function dryRunAutomation(string $automation, DryRun $dryRun): RedirectResponse
    {
        $this->authorizePanel(Permission::AutomationsView);
        try {
            $r = $dryRun->run($automation, $this->actor(), maxCandidates: 5000);
        } catch (AutomationDefinitionError $e) {
            return $this->failed($e->getMessage());
        }
        unset($r['samples'], $r['trigger']);

        return back()->with('automation_dry_run', $r)->with('status', 'Dry-run tamamlandı (hiçbir şey gönderilmedi).');
    }

    public function automationState(Request $request, string $automation, Automations $automations): RedirectResponse
    {
        $this->authorizePanel(Permission::AutomationsManage);
        $action = (string) $request->validate(['action' => 'required|in:publish,pause,archive'])['action'];
        try {
            match ($action) {
                'publish' => $automations->publish($automation, $this->actor()),
                'pause' => $automations->pause($automation, $this->actor()),
                default => $automations->archive($automation, $this->actor()),
            };
        } catch (AutomationDefinitionError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Otomasyon durumu güncellendi (otomasyonlar yalnızca planlı aksiyon üretir).');
    }
}
