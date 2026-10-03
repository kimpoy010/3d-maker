<?php

namespace App\Http\Controllers;

use App\Enums\StylizationStatus;
use App\Exceptions\DailyLimitReachedException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Exceptions\StylizationNotReadyException;
use App\Exceptions\StylizerDisabledException;
use App\Http\Requests\StoreStylizationRequest;
use App\Http\Resources\StylizationResource;
use App\Models\Style;
use App\Models\Stylization;
use App\Services\Credits\CreditService;
use App\Services\StylizationService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StylizationController extends Controller
{
    public function store(StoreStylizationRequest $request, StylizationService $stylizations): RedirectResponse
    {
        $style = Style::active()->findOrFail($request->integer('style_id'));

        try {
            $stylization = $stylizations->create($request->user(), $request->file('photo'), $style);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'style_id' => 'Not enough balance for a preview: it costs '.Money::peso($e->required).' and you have '.Money::peso($e->balance).'.',
            ]);
        } catch (InvalidImageException) {
            throw ValidationException::withMessages(['photo' => 'We could not read that image. Try another photo.']);
        } catch (StylizerDisabledException) {
            throw ValidationException::withMessages(['photo' => 'Previews are temporarily unavailable. Please try again later.']);
        } catch (DailyLimitReachedException) {
            throw ValidationException::withMessages(['photo' => "You've reached today's preview limit. Try again tomorrow."]);
        }

        return redirect()->route('stylizations.show', $stylization);
    }

    public function show(Request $request, Stylization $stylization, CreditService $credits): Response|RedirectResponse
    {
        Gate::authorize('view', $stylization);

        if ($stylization->status === StylizationStatus::Approved && $stylization->creation_id) {
            return redirect()->route('creations.show', $stylization->creation_id);
        }

        return Inertia::render('stylizations/Show', [
            'stylization' => StylizationResource::make($stylization->load('style'))->resolve(),
            'balance' => $credits->balance($request->user()),
            'restyle_cost' => (int) config('credits.restyle_cost'),
        ]);
    }

    public function approve(Request $request, Stylization $stylization, StylizationService $stylizations): RedirectResponse
    {
        Gate::authorize('approve', $stylization);

        try {
            $creation = $stylizations->approve($request->user(), $stylization);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'approve' => 'Not enough balance: building the 3D model costs '.Money::peso($e->required).' and you have '.Money::peso($e->balance).'.',
            ]);
        } catch (StylizationNotReadyException) {
            throw ValidationException::withMessages(['approve' => 'This preview can no longer be approved.']);
        } catch (InvalidImageException) {
            throw ValidationException::withMessages(['approve' => 'This preview can no longer be used. Try again or start over.']);
        }

        return redirect()->route('creations.show', $creation);
    }

    public function retry(Request $request, Stylization $stylization, StylizationService $stylizations): RedirectResponse
    {
        Gate::authorize('retry', $stylization);

        try {
            $new = $stylizations->retry($request->user(), $stylization);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'retry' => 'Not enough balance for another preview: it costs '.Money::peso($e->required).' and you have '.Money::peso($e->balance).'.',
            ]);
        } catch (StylizationNotReadyException) {
            throw ValidationException::withMessages(['retry' => 'This preview cannot be retried.']);
        } catch (StylizerDisabledException) {
            throw ValidationException::withMessages(['retry' => 'Previews are temporarily unavailable. Please try again later.']);
        } catch (DailyLimitReachedException) {
            throw ValidationException::withMessages(['retry' => "You've reached today's preview limit. Try again tomorrow."]);
        }

        return redirect()->route('stylizations.show', $new);
    }

    public function destroy(Stylization $stylization, StylizationService $stylizations): RedirectResponse
    {
        Gate::authorize('discard', $stylization);

        abort_unless($stylizations->discard($stylization), 409, 'This preview cannot be discarded right now.');

        return redirect()->route('creations.index');
    }
}
