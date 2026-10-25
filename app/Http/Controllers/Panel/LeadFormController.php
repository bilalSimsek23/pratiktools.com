<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Leads\LeadError;
use App\Leads\LeadForms;
use App\Leads\LeadReport;
use App\Tenancy\Permission;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class LeadFormController extends Controller
{
    use PanelHelpers;

    public function index(): View
    {
        $this->authorizePanel(Permission::LeadsView);
        $forms = DB::table('lead_forms as f')->orderBy('f.name')->get(['f.*',
            DB::raw('(SELECT coalesce(sum(m.accepted), 0) FROM lead_metrics_daily m WHERE m.tenant_id = f.tenant_id AND m.form_id = f.id AND m.day >= current_date - 30) AS accepted_30d')]);

        return view('panel.forms.index', ['forms' => $forms, 'canManage' => $this->can(Permission::FormsManage)]);
    }

    public function store(Request $request, LeadForms $forms): RedirectResponse
    {
        $this->authorizePanel(Permission::FormsManage);
        $d = $request->validate(['name' => 'required|string|max:120', 'placement' => 'required|in:popup,footer,landing_page,checkout', 'consent_text' => 'required|string|max:4000',
            'success_message' => 'required|string|max:300', 'origins' => 'required|string|max:1000', 'double_opt_in' => 'nullable|boolean']);
        try {
            $id = $forms->create($d['name'], $d['placement'], $d['consent_text'], $d['success_message'],
                array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $d['origins']) ?: []))), $this->actor(), doubleOptIn: (bool) ($d['double_opt_in'] ?? false));
        } catch (LeadError $e) {
            return $this->failed($e->getMessage());
        } catch (QueryException) {
            return $this->failed('Bu isimde bir form zaten var.');
        }

        return redirect()->route('panel.forms.show', $id)->with('status', 'Form oluşturuldu (pasif). Test ettikten sonra aktifleştirin.');
    }

    public function show(string $form, LeadReport $report): View
    {
        $this->authorizePanel(Permission::LeadsView);
        $f = DB::table('lead_forms')->where('id', $form)->first() ?? abort(404);

        return view('panel.forms.show', ['f' => $f, 'report' => $report->report(30, $f->id), 'endpoint' => url("/public/v1/forms/{$f->public_id}/subscribe"),
            'origins' => (array) json_decode($f->allowed_origins, true), 'canManage' => $this->can(Permission::FormsManage)]);
    }

    public function active(Request $request, string $form, LeadForms $forms): RedirectResponse
    {
        $this->authorizePanel(Permission::FormsManage);
        try {
            $forms->setActive($form, (bool) $request->validate(['active' => 'required|boolean'])['active'], $this->actor());
        } catch (LeadError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done('Form durumu güncellendi.');
    }

    public function consent(Request $request, string $form, LeadForms $forms): RedirectResponse
    {
        $this->authorizePanel(Permission::FormsManage);
        try {
            $v = $forms->changeConsentText($form, (string) $request->validate(['consent_text' => 'required|string|max:4000'])['consent_text'], $this->actor());
        } catch (LeadError $e) {
            return $this->failed($e->getMessage());
        }

        return $this->done("İzin metni güncellendi (versiyon {$v}).");
    }

    public function leads(LeadReport $report): View
    {
        $this->authorizePanel(Permission::LeadsView);
        $forms = DB::table('lead_forms')->orderBy('name')->get(['id', 'name', 'placement']);

        return view('panel.leads', ['report' => $report->report(30), 'perForm' => $forms->map(fn ($f) => ['form' => $f, 'report' => $report->report(30, $f->id)])]);
    }
}
