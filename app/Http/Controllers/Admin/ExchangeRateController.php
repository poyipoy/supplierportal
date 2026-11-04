<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesMasterDataRoutes;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnualExchangeRatesRequest;
use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Services\RegionalDisplayFormatter;
use App\Support\ServerTabsResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExchangeRateController extends Controller
{
    use ResolvesMasterDataRoutes;

    /**
     * Display a listing of the exchange rates.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        $request->validate([
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
        ]);
        $filters = $request->validateWithBag('annualFilters', [
            'tab' => ['nullable', Rule::in(['annual', 'effective'])],
            'annual_year' => ['nullable', 'integer', 'between:1000,9999'],
            'annual_edit_year' => ['nullable', 'integer', 'between:1000,9999'],
            'annual_new' => ['nullable', 'boolean'],
            'annual_new_year' => ['nullable', 'integer', 'between:1000,9999'],
            'annual_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $errors = $request->session()->get('errors', new ViewErrorBag);
        $annualHasErrors = $errors->getBag('annualRates')->any();
        $annualOldInput = $annualHasErrors ? $request->session()->getOldInput() : [];
        $activeTab = $annualHasErrors || $errors->getBag('annualFilters')->any()
            ? 'annual'
            : ($filters['tab'] ?? ($request->hasAny(['annual_year', 'annual_edit_year', 'annual_new', 'annual_new_year', 'annual_page']) ? 'annual' : 'effective'));

        if ($activeTab === 'effective') {
            $query = ExchangeRate::with('creator')->orderBy('valid_from', 'desc');
            if ($request->filled('currency')) {
                $query->where('currency', $request->currency);
            }
            $rates = $query->paginate(30)->withQueryString();
            $currencyCounts = ExchangeRate::selectRaw('currency, COUNT(*) as total')
                ->groupBy('currency')->pluck('total', 'currency')->toArray();
            $totalRates = ExchangeRate::count();

            return $this->indexResponse($request, compact('activeTab', 'rates', 'currencyCounts', 'totalRates'));
        }

        // Restore annual input only from its own error bag; malformed flashed arrays are not display values.
        $oldYear = $annualOldInput['year'] ?? null;
        $restoredYear = is_scalar($oldYear) ? filter_var($oldYear, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1000, 'max_range' => 9999],
        ]) : false;
        $oldRevision = is_scalar($annualOldInput['expected_revision'] ?? null) ? (string) $annualOldInput['expected_revision'] : '';
        $annualNew = $annualHasErrors
            ? $restoredYear === false || $oldRevision === '0'
            : $request->boolean('annual_new');
        $annualEditYear = $annualHasErrors
            ? ($restoredYear === false ? null : (int) $restoredYear)
            : ($annualNew ? ($filters['annual_new_year'] ?? null) : ($filters['annual_edit_year'] ?? null));
        $annualEditYear = $annualEditYear === null ? null : (int) $annualEditYear;
        $annualSelectedRates = $annualEditYear === null ? collect() : AnnualExchangeRate::query()
            ->latestRevisions()->where('year', $annualEditYear)->get()->keyBy('currency');
        $annualDuplicateYear = null;
        if ($annualEditYear !== null && ! $annualHasErrors) {
            if ($annualNew && $annualSelectedRates->isNotEmpty()) {
                $annualDuplicateYear = $annualEditYear;
                $annualEditYear = null;
            } elseif (! $annualNew && $annualSelectedRates->isEmpty()) {
                abort(404);
            }
        }

        $annualPage = (int) ($filters['annual_page'] ?? 1);
        if ($annualEditYear !== null && $annualSelectedRates->isNotEmpty() && empty($filters['annual_year'])) {
            $annualPage = $this->annualPageForYear($annualEditYear);
        }
        $annualYears = AnnualExchangeRate::query()->select('year')->groupBy('year')
            ->when(! empty($filters['annual_year']), fn ($query) => $query->where('year', $filters['annual_year']))
            ->orderByDesc('year')->paginate(5, ['year'], 'annual_page', $annualPage)
            ->appends(array_filter(['tab' => 'annual', 'annual_year' => $filters['annual_year'] ?? null]));
        $annualMatrixYears = $annualYears->getCollection()->pluck('year')
            ->when($annualEditYear !== null, fn ($years) => $years->push($annualEditYear))
            ->unique()->sort()->values()->all();
        $annualRates = AnnualExchangeRate::query()->latestRevisions()
            ->whereIn('year', $annualMatrixYears)->get()->groupBy('year');
        $annualRevision = $annualHasErrors
            ? $oldRevision
            : (string) ($annualNew ? 0 : ($annualSelectedRates->first()?->revision ?? 0));
        $annualInputRates = [];
        foreach (AnnualExchangeRate::CURRENCIES as $currency) {
            $value = $annualHasErrors
                ? data_get($annualOldInput, 'rates.'.$currency, '')
                : ($annualNew ? '' : $annualSelectedRates->get($currency)?->rate_to_idr);
            $annualInputRates[$currency] = is_scalar($value) ? (string) $value : '';
        }
        $annualListQuery = array_filter([
            'tab' => 'annual', 'annual_page' => $annualYears->currentPage(),
            'annual_year' => $filters['annual_year'] ?? null,
        ]);

        return $this->indexResponse($request, compact('activeTab', 'annualYears', 'annualMatrixYears',
            'annualRates', 'annualNew', 'annualEditYear', 'annualDuplicateYear', 'annualHasErrors',
            'annualRevision', 'annualInputRates', 'annualListQuery'));
    }

    private function indexResponse(Request $request, array $data)
    {
        $data['masterRoutePrefix'] = $this->masterRoutePrefix();

        if (ServerTabsResponse::wants($request)) {
            $data['regionalFormatter'] = app(RegionalDisplayFormatter::class);

            return ServerTabsResponse::make(
                view('admin.exchange-rates._content', $data)->render(),
                $data['activeTab'], $request,
                ['nav' => view('admin.exchange-rates._tabs', $data)->render()],
            )->header('Cache-Control', 'private, no-store');
        }

        return view('admin.exchange-rates.index', $data);
    }

    public function storeAnnual(StoreAnnualExchangeRatesRequest $request)
    {
        Gate::authorize('create', ExchangeRate::class);

        $data = $request->validated();
        $conflict = fn () => ValidationException::withMessages((int) $data['expected_revision'] === 0
            ? ['year' => __('annual_rates.year_exists', ['year' => $data['year']])]
            : ['expected_revision' => __('annual_rates.conflict')])
            ->errorBag('annualRates')->redirectTo(route($this->masterRoutePrefix().'.exchange-rates.index', ['tab' => 'annual']));
        try {
            DB::transaction(function () use ($data, $request, $conflict): void {
                $latest = AnnualExchangeRate::where('year', $data['year'])->orderByDesc('revision')->lockForUpdate()->first();
                $revision = $latest?->revision ?? 0;
                if ($revision !== (int) $data['expected_revision']) {
                    throw $conflict();
                }
                foreach (AnnualExchangeRate::CURRENCIES as $currency) {
                    AnnualExchangeRate::create([
                        'year' => $data['year'], 'revision' => $revision + 1, 'currency' => $currency,
                        'rate_to_idr' => $data['rates'][$currency], 'created_by' => $request->user()->id,
                    ]);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw $conflict();
        } catch (QueryException $exception) {
            // The transaction has exhausted its retries and rolled back; do not expose SQL to the editor.
            if (($exception->errorInfo[0] ?? null) !== '40001' || (int) ($exception->errorInfo[1] ?? 0) !== 1213) {
                throw $exception;
            }

            throw ValidationException::withMessages(['expected_revision' => __('annual_rates.busy')])
                ->errorBag('annualRates')->redirectTo(route($this->masterRoutePrefix().'.exchange-rates.index', ['tab' => 'annual']));
        }

        $redirect = route($this->masterRoutePrefix().'.exchange-rates.index', ['tab' => 'annual', 'annual_page' => $this->annualPageForYear((int) $data['year'])]);
        $message = __('annual_rates.saved', ['year' => $data['year']]);
        if ($request->expectsJson()) {
            return response()->json(compact('redirect', 'message'));
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    private function annualPageForYear(int $year): int
    {
        return intdiv(AnnualExchangeRate::query()->where('year', '>', $year)->distinct()->count('year'), 5) + 1;
    }

    /**
     * Store a newly created exchange rate in storage.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', ExchangeRate::class);

        $request->validate([
            'currency' => ['required', Rule::in(ExchangeRate::CURRENCIES)],
            'rate_to_idr' => 'required|numeric|min:1',
            'valid_from' => 'required|date',
        ]);

        ExchangeRate::create([
            'currency' => $request->currency,
            'rate_to_idr' => $request->rate_to_idr,
            'valid_from' => $request->valid_from,
            'created_by' => auth()->id(),
        ]);

        $message = __('admin.feedback.rate_added', ['currency' => $request->currency]);
        if ($request->expectsJson()) {
            return response()->json(['redirect' => route($this->masterRoutePrefix().'.exchange-rates.index', ['tab' => 'effective']), 'message' => $message]);
        }

        return back()->with('success', $message);
    }
}
