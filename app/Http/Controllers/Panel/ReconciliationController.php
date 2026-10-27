<?php

namespace App\Http\Controllers\Panel;

use App\Consent\ConsentStrategy;
use App\Consent\Reconciliation\IysReconciliationImporter;
use App\Consent\Reconciliation\ReconciliationError;
use App\Consent\Reconciliation\ReconciliationFile;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Panel\Concerns\PanelHelpers;
use App\Tenancy\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * İYS > Mutabakat: upload an İYS change report, review the dry-run, apply
 * it. The uploaded file is analysed in the request and not kept; rows show
 * masked addresses only (known recipients) or nothing (unknown ones).
 */
final class ReconciliationController extends Controller
{
    use PanelHelpers;

    public const OUTCOMES = ['onay', 'ret', 'unchanged', 'conflict', 'invalid', 'unknown_recipient', 'marketplace_excluded', 'duplicate'];

    public function index(ConsentStrategy $strategy): View
    {
        $this->authorizePanel(Permission::IntegrationsView);

        return view('panel.reconciliation.index', ['state' => $strategy->state(), 'canReconcile' => $this->can(Permission::ConsentReconcile),
            'maxMb' => (int) floor((int) config('consent.reconciliation.max_file_bytes') / 1048576),
            'batches' => DB::table('iys_reconciliation_batches')->orderByDesc('created_at')->limit(50)
                ->get(['id', 'original_filename', 'period_start', 'period_end', 'status', 'error', 'dry_run_counts', 'applied_counts', 'created_at', 'applied_at'])]);
    }

    public function store(Request $request, IysReconciliationImporter $importer): RedirectResponse
    {
        $this->authorizePanel(Permission::ConsentReconcile);
        $d = $request->validate([
            'report' => 'required|file|max:'.(int) ceil((int) config('consent.reconciliation.max_file_bytes') / 1024),
            'period_end' => 'required|date_format:Y-m-d',
            'period_start' => 'nullable|date_format:Y-m-d',
        ]);
        $file = $request->file('report');
        try {
            $result = $importer->analyze(ReconciliationFile::open((string) $file->getRealPath(), $file->getClientOriginalName()),
                CarbonImmutable::parse($d['period_end']), isset($d['period_start']) ? CarbonImmutable::parse($d['period_start']) : null, $this->actor());
        } catch (ReconciliationError $e) {
            return $this->failed(self::message($e->reason));
        }

        return redirect()->route('panel.reconciliation.show', $result['batch_id'])
            ->with('status', $result['status'] === 'analyzed' ? 'Dosya analiz edildi (dry-run). İzin durumu değişmedi; sonuçları kontrol edip uygulayın.' : self::message((string) $result['error']));
    }

    public function show(Request $request, string $batch): View
    {
        $this->authorizePanel(Permission::IntegrationsView);
        $b = DB::table('iys_reconciliation_batches')->where('id', $batch)->first() ?? abort(404);
        $outcome = in_array($request->query('outcome'), self::OUTCOMES, true) ? (string) $request->query('outcome') : null;
        $rows = DB::table('iys_consent_changes as c')->leftJoin('consent_recipients as r', fn ($j) => $j->on('r.id', '=', 'c.recipient_id')->on('r.tenant_id', '=', 'c.tenant_id'))
            ->where('c.batch_id', $b->id)->when($outcome !== null, fn ($q) => $q->where('c.outcome', $outcome))->orderBy('c.row_number')->limit(200)
            ->get(['c.row_number', 'c.channel', 'c.recipient_type', 'c.status', 'c.occurred_at', 'c.outcome', 'c.detail', 'c.address_hash', 'r.address'])
            ->map(function ($r) {
                $r->masked = $r->address === null ? null : self::mask((string) $r->address);
                unset($r->address);

                return $r;
            });

        return view('panel.reconciliation.show', ['b' => $b, 'rows' => $rows, 'outcome' => $outcome, 'outcomes' => self::OUTCOMES,
            'counts' => (array) json_decode((string) ($b->status === 'applied' ? $b->applied_counts : $b->dry_run_counts), true), 'canApply' => $this->can(Permission::ConsentReconcile)]);
    }

    public function apply(string $batch, IysReconciliationImporter $importer): RedirectResponse
    {
        $this->authorizePanel(Permission::ConsentReconcile);
        try {
            $importer->apply($batch, $this->actor());
        } catch (ReconciliationError $e) {
            return $this->failed(self::message($e->reason));
        }

        return $this->done('Mutabakat uygulandı. RET kayıtları anında engeller; çakışmalar kayda alındı.');
    }

    public static function mask(string $address): string
    {
        return str_contains($address, '@')
            ? (string) preg_replace('/^(.).*@(.).*(\.[^.]+)$/', '$1***@$2***$3', $address)
            : substr($address, 0, 4).str_repeat('*', max(0, strlen($address) - 6)).substr($address, -2);
    }

    public static function message(string $reason): string
    {
        return match ($reason) {
            'FORMAT_NOT_CONFIGURED' => 'İYS değişiklik raporu formatı henüz tanımlı değil. Resmî format/örnek dosya gelmeden rapor işlenmez (tahmin edilmez).',
            'ALREADY_APPLIED' => 'Bu rapor daha önce uygulanmış; aynı dosya ikinci kez uygulanamaz.',
            'NOT_ANALYZED' => 'Yalnızca analiz edilmiş ve uygulanmamış bir rapor uygulanabilir.',
            'FILE_EMPTY' => 'Dosya boş.',
            'FILE_TOO_LARGE' => 'Dosya izin verilen boyuttan büyük.',
            'TOO_MANY_ROWS' => 'Dosyada izin verilenden fazla satır var.',
            'INVALID_PERIOD' => 'Rapor dönemi geçersiz (bitiş bugünden ileri olamaz, başlangıç bitişten sonra olamaz).',
            'NOT_FOUND' => 'Rapor bulunamadı.',
            default => 'Rapor işlenemedi ('.$reason.').',
        };
    }
}
