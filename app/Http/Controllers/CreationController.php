<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidImageException;
use App\Http\Requests\StoreCreationRequest;
use App\Http\Resources\CreationResource;
use App\Models\Creation;
use App\Models\Style;
use App\Services\Credits\CreditService;
use App\Services\CreationService;
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
                    'credit_cost' => $s->credit_cost,
                ])->values(),
            'balance' => $credits->balance($request->user()),
        ]);
    }

    public function store(StoreCreationRequest $request, CreationService $creations): RedirectResponse
    {
        $style = Style::active()->findOrFail($request->integer('style_id'));

        try {
            $creation = $creations->submit($request->user(), $request->file('photo'), $style);
        } catch (InsufficientCreditsException $e) {
            throw ValidationException::withMessages([
                'style_id' => "Not enough credits: this style costs {$e->required} and you have {$e->balance}.",
            ]);
        } catch (InvalidImageException) {
            throw ValidationException::withMessages(['photo' => 'We could not read that image. Try another photo.']);
        }

        return redirect()->route('creations.show', $creation);
    }

    public function index(Request $request): Response
    {
        $creations = Creation::with('style')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(60)
            ->get();

        return Inertia::render('creations/Index', [
            'creations' => $creations->map(fn (Creation $c) => CreationResource::make($c)->resolve())->values(),
        ]);
    }

    public function show(Creation $creation): Response
    {
        Gate::authorize('view', $creation);

        return Inertia::render('creations/Show', [
            'creation' => CreationResource::make($creation->load('style'))->resolve(),
        ]);
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
