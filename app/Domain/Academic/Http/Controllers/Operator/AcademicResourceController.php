<?php

namespace App\Domain\Academic\Http\Controllers\Operator;

use App\Domain\Academic\Contracts\AcademicAudit;
use App\Domain\Academic\Http\Operator\OperatorResources;
use App\Domain\Academic\Models\Topic;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Operator screens for the academic structure (brief section 20): countries,
 * frameworks, curricula, levels, subjects, offerings, topics, institutions and
 * languages. Behind the existing `operator` middleware until the ops agent's
 * role matrix lands. Every change is audited through the AcademicAudit seam.
 */
class AcademicResourceController extends Controller
{
    public function __construct(private readonly AcademicAudit $audit) {}

    public function home(): View
    {
        return view('academic::operator.home', [
            'resources' => collect(OperatorResources::all())->map(fn (array $r, string $key) => [
                'key' => $key,
                'title' => $r['title'],
                'count' => $r['model']::query()->count(),
            ])->values(),
        ]);
    }

    public function index(Request $request, string $resource): View
    {
        $config = $this->config($resource);
        $query = $config['model']::query()->with($config['with'] ?? []);

        foreach (array_keys($config['filters'] ?? []) as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->input($column));
            }
        }
        foreach ($config['order'] as $column) {
            $query->orderBy($column);
        }

        return view('academic::operator.resource', [
            'key' => $resource,
            'config' => $config,
            'rows' => $query->paginate(50)->withQueryString(),
            'hasActiveFlag' => $this->hasActiveFlag($config['model']),
        ]);
    }

    public function store(Request $request, string $resource): RedirectResponse
    {
        $config = $this->config($resource);
        $data = $this->validated($request, $config, null);

        $model = new $config['model'];
        $model->forceFill($data + $this->defaults($resource, $data))->save();
        $this->audit->record($request->user(), "academic.{$resource}.created", $model, ['attributes' => $data]);

        return redirect()->route('operator.academic.index', ['resource' => $resource] + $request->only(array_keys($config['filters'] ?? [])))
            ->with('status', 'Added.');
    }

    public function update(Request $request, string $resource, int $id): RedirectResponse
    {
        $config = $this->config($resource);
        $model = $config['model']::query()->findOrFail($id);
        $data = $this->validated($request, $config, $model);

        $before = $model->only(array_keys($data));
        $model->forceFill($data)->save();
        $this->audit->record($request->user(), "academic.{$resource}.updated", $model, ['before' => $before, 'after' => $data]);

        return back()->with('status', 'Saved.');
    }

    public function toggle(Request $request, string $resource, int $id): RedirectResponse
    {
        $config = $this->config($resource);
        abort_unless($this->hasActiveFlag($config['model']), 404);

        $model = $config['model']::query()->findOrFail($id);
        $model->forceFill(['is_active' => ! $model->is_active])->save();
        $this->audit->record($request->user(), "academic.{$resource}.".($model->is_active ? 'activated' : 'deactivated'), $model);

        return back()->with('status', $model->is_active ? 'Active again.' : 'Deactivated. Nothing that already uses it changes.');
    }

    /** @return array<string, mixed> */
    private function config(string $resource): array
    {
        return OperatorResources::all()[$resource] ?? abort(404);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $config, ?Model $model): array
    {
        $rules = [];
        foreach ($config['fields'] as $name => $field) {
            $rules[$name] = ($field['rules'])($model);
        }

        $data = $request->validate($rules);

        foreach ($config['fields'] as $name => $field) {
            $value = $data[$name] ?? null;
            $data[$name] = match ($field['type']) {
                'bool' => $request->boolean($name),
                'list' => $this->list($value),
                'number' => $value === null || $value === '' ? null : (int) $value,
                'select' => $value === null || $value === '' ? null : $value,
                default => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            };
            if (($field['transform'] ?? null) === 'upper' && is_string($data[$name])) {
                $data[$name] = strtoupper($data[$name]);
            }
        }

        if ($config['model'] === Topic::class && blank($data['slug'] ?? null)) {
            $data['slug'] = Str::slug($data['name']);
            $taken = Topic::query()->where('curriculum_subject_level_id', $data['curriculum_subject_level_id'])
                ->where('slug', $data['slug'])->when($model, fn ($q) => $q->whereKeyNot($model->getKey()))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['name' => 'This offering already has a topic with that name.']);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function defaults(string $resource, array $data): array
    {
        return match ($resource) {
            'subjects' => ['is_active' => true],
            default => [],
        };
    }

    /** @return list<string>|null */
    private function list(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $items = array_map(fn (string $v) => Str::lower(trim($v)), explode(',', $value));

        return array_values(array_unique(array_filter($items, fn (string $v) => $v !== '')));
    }

    /** @param  class-string<Model>  $class */
    private function hasActiveFlag(string $class): bool
    {
        return in_array('is_active', (new $class)->getFillable(), true);
    }
}
