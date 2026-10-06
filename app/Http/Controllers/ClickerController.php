<?php

namespace App\Http\Controllers;

use App\Exceptions\ClickerLimitReachedException;
use App\Exceptions\ClickerStateException;
use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Http\Requests\SaveClickerRequest;
use App\Http\Resources\ClickerBodyResource;
use App\Http\Resources\ClickerResource;
use App\Models\Clicker;
use App\Models\ClickerBody;
use App\Services\Clickers\ClickerImages;
use App\Services\Clickers\ClickerService;
use App\Services\Credits\CreditService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClickerController extends Controller
{
    public function index(Request $request): Response
    {
        $clickers = Clicker::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return Inertia::render('clickers/Index', [
            'clickers' => $clickers->map(fn (Clicker $c) => ClickerResource::make($c)->resolve())->values(),
            'limit' => (int) config('clicker.max_per_user'),
        ]);
    }

    public function create(Request $request, CreditService $credits): Response
    {
        return Inertia::render('clickers/Edit', $this->editorProps($request, null, $credits));
    }

    public function show(Request $request, Clicker $clicker, CreditService $credits): Response
    {
        Gate::authorize('view', $clicker);

        return Inertia::render('clickers/Edit', $this->editorProps($request, $clicker, $credits));
    }

    public function store(SaveClickerRequest $request, ClickerService $clickers, ClickerImages $images): RedirectResponse
    {
        $body = ClickerBody::active()->findOrFail($request->integer('clicker_body_id'));

        try {
            $clicker = $clickers->create(
                $request->user(),
                $body,
                $request->string('name')->toString(),
                $request->settings(),
                $images->artwork($request->file('artwork')->getRealPath()),
                $this->thumbnail($request, $images),
            );
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages(['artwork' => $e->getMessage()]);
        } catch (ClickerLimitReachedException) {
            throw ValidationException::withMessages(['name' => $this->limitMessage()]);
        }

        return redirect()->route('clickers.show', $clicker);
    }

    public function update(SaveClickerRequest $request, Clicker $clicker, ClickerService $clickers, ClickerImages $images): RedirectResponse
    {
        Gate::authorize('update', $clicker);

        $body = ClickerBody::active()->findOrFail($request->integer('clicker_body_id'));

        try {
            $clickers->update(
                $clicker,
                $body,
                $request->string('name')->toString(),
                $request->settings(),
                $request->hasFile('artwork') ? $images->artwork($request->file('artwork')->getRealPath()) : null,
                $this->thumbnail($request, $images),
            );
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages(['artwork' => $e->getMessage()]);
        } catch (ClickerStateException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return back();
    }

    public function duplicate(Request $request, Clicker $clicker, ClickerService $clickers): RedirectResponse
    {
        Gate::authorize('view', $clicker);

        try {
            $copy = $clickers->duplicate($request->user(), $clicker);
        } catch (ClickerLimitReachedException) {
            throw ValidationException::withMessages(['name' => $this->limitMessage()]);
        }

        return redirect()->route('clickers.show', $copy);
    }

    public function destroy(Clicker $clicker, ClickerService $clickers): RedirectResponse
    {
        Gate::authorize('delete', $clicker);

        try {
            $clickers->destroy($clicker);
        } catch (ClickerStateException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return redirect()->route('clickers.index');
    }

    public function unlock(Request $request, Clicker $clicker, ClickerService $clickers): RedirectResponse
    {
        Gate::authorize('unlock', $clicker);

        try {
            $clickers->unlock($request->user(), $clicker);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'unlock' => 'Not enough balance: unlocking downloads costs '.Money::peso($e->required).' and you have '.Money::peso($e->balance).'.',
            ]);
        } catch (ClickerStateException $e) {
            throw ValidationException::withMessages(['unlock' => $e->getMessage()]);
        }

        return back();
    }

    /** The thumbnail is a nicety: a bad one is dropped instead of failing the save. */
    private function thumbnail(SaveClickerRequest $request, ClickerImages $images): ?string
    {
        if (! $request->hasFile('thumbnail')) {
            return null;
        }

        try {
            return $images->thumbnail($request->file('thumbnail')->getRealPath());
        } catch (InvalidImageException) {
            return null;
        }
    }

    private function limitMessage(): string
    {
        return 'You have reached the limit of '.config('clicker.max_per_user').' saved clickers. Delete one to make room.';
    }

    /** @return array<string, mixed> */
    private function editorProps(Request $request, ?Clicker $clicker, CreditService $credits): array
    {
        return [
            'clicker' => $clicker ? ClickerResource::make($clicker)->resolve() : null,
            // The design's own body is always included, even once retired, so the editor previews the right one.
            'bodies' => ClickerBody::query()
                ->where(fn ($query) => $query->where('active', true)->when($clicker, fn ($q) => $q->orWhere('id', $clicker->clicker_body_id)))
                ->orderBy('id')->get()
                ->map(fn (ClickerBody $b) => ClickerBodyResource::make($b)->resolve())->values(),
            'balance' => $credits->balance($request->user()),
            'unlock_cost' => (int) config('credits.clicker_cost'),
            'max_colors' => (int) config('clicker.max_colors'),
        ];
    }
}
