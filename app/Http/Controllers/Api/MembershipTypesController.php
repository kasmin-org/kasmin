<?php

namespace App\Http\Controllers\Api;

use App\Casts\OneText;
use App\Models\MembershipType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Membership types with their yearly fee. Scopes: people:read, people:write.
 */
class MembershipTypesController extends ApiController
{
    /** All membership types. */
    public function index(): JsonResponse
    {
        $this->scope('people:read');

        return response()->json(['data' => MembershipType::query()->orderBy('sort')->orderBy('id')->get()->map(fn (MembershipType $t) => $this->present($t))->values()]);
    }

    /** Creates a membership type. The key cannot be changed later. */
    public function store(Request $request): JsonResponse
    {
        $this->scope('people:write');
        $type = MembershipType::query()->create($this->data($request, true));

        return response()->json(['data' => $this->present($type)], 201);
    }

    /** Changes the names, whether the type pays a fee, and the yearly fee. */
    public function update(Request $request, string $type): JsonResponse
    {
        $this->scope('people:write');
        $model = MembershipType::query()->where('key', $type)->firstOrFail();
        $model->update($this->data($request, false));

        return response()->json(['data' => $this->present($model)]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request, bool $new): array
    {
        $need = $new ? 'required' : 'sometimes';
        $request->merge(OneText::collapse($request->only('name'), ['name']));
        $data = $request->validate([
            'key' => $new ? ['required', 'string', 'alpha_dash', 'max:64', 'unique:membership_types,key'] : ['prohibited'],
            'name' => [$need, 'string', 'max:150'],
            'pays_fee' => ['sometimes', 'boolean'],
            'fee' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
        ]);
        if (array_key_exists('fee', $data)) {
            $data['fee_cents'] = (int) round(((float) $data['fee']) * 100);
        }

        return array_diff_key($data, array_flip(['fee']));
    }

    /** @return array<string, mixed> */
    private function present(MembershipType $t): array
    {
        return ['key' => $t->key, 'name' => $t->name, 'pays_fee' => (bool) $t->getAttribute('pays_fee'), 'fee' => (int) $t->fee_cents / 100];
    }
}
