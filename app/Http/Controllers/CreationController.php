<?php

namespace App\Http\Controllers;

use App\Enums\StylizationStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\StylizationNotReadyException;
use App\Http\Resources\CreationResource;
use App\Http\Resources\StylizationResource;
use App\Models\Creation;
use App\Models\Style;
use App\Models\Stylization;
use App\Services\CreationService;
use App\Services\Credits\CreditService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CreationController extends Controller
{
    public function create(Request $request, CreditService $credits): Response
    {
        return Inertia::render('Create', [
            'styles' => Style::active()->orderBy('subject')->orderBy('id')->get()
                ->map(fn (Style $s) => [
                    'id' => $s->id,
                    'subject' => $s->subject->value,
                    'name' => $s->name,
                    'look' => $s->look,
                    'preview' => $s->preview_image ? '/'.$s->preview_image : null,
                    'credit_cost' => $s->credit_cost,
                ])->values(),
            'balance' => $credits->balance($request->user()),
            'restyle_cost' => (int) config('credits.restyle_cost'),
            'download_cost' => (int) config('credits.download_cost'),
        ]);
    }

    public function index(Request $request): Response
    {
        $creations = Creation::with('style')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(60)
            ->get();

        $previews = Stylization::with('style')
            ->where('user_id', $request->user()->id)
            ->where('status', StylizationStatus::Ready->value)
            ->latest()
            ->get();

        return Inertia::render('creations/Index', [
            'creations' => $creations->map(fn (Creation $c) => CreationResource::make($c)->resolve())->values(),
            'previews' => $previews->map(fn (Stylization $s) => StylizationResource::make($s)->resolve())->values(),
        ]);
    }

    public function retry(Request $request, Creation $creation, CreationService $creations): RedirectResponse
    {
        Gate::authorize('retry', $creation);

        try {
            $new = $creations->retryFailed($request->user(), $creation);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'retry' => 'Not enough balance: building the 3D model costs '.Money::peso($e->required).' and you have '.Money::peso($e->balance).'.',
            ]);
        } catch (StylizationNotReadyException) {
            throw ValidationException::withMessages(['retry' => 'This creation cannot be retried.']);
        }

        return redirect()->route('creations.show', $new);
    }

    public function show(Request $request, Creation $creation, CreditService $credits): Response
    {
        Gate::authorize('view', $creation);

        return Inertia::render('creations/Show', [
            'creation' => CreationResource::make($creation->load('style'))->resolve(),
            'balance' => $credits->balance($request->user()),
        ]);
    }

    public function unlock(Request $request, Creation $creation, CreationService $creations): RedirectResponse
    {
        Gate::authorize('view', $creation);

        try {
            $creations->unlockDownloads($request->user(), $creation);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'unlock' => 'Not enough balance: unlocking downloads costs '.Money::peso($e->required).' and you have '.Money::peso($e->balance).'.',
            ]);
        } catch (StylizationNotReadyException) {
            throw ValidationException::withMessages(['unlock' => 'This model is not ready to download yet.']);
        }

        return back();
    }

    public function destroy(Creation $creation): RedirectResponse
    {
        Gate::authorize('delete', $creation);

        $disk = Storage::disk('local');
        $disk->delete($creation->source_image_path);
        $disk->deleteDirectory("creations/{$creation->id}");
        $creation->delete();

        return redirect()->route('creations.index');
    }
}
