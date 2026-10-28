<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ManagementUserResource;
use App\Services\Management\ManagementContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ManagementUserController extends Controller
{
    public function __construct(private readonly ManagementContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $input = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $environment = $this->context->environment($request);
        $perPage = (int) ($input['per_page'] ?? 15);
        $users = $this->context->users($environment)
            ->orderBy('users.id')
            ->paginate($perPage)
            ->appends(['per_page' => $perPage]);

        return ManagementUserResource::collection($users);
    }

    public function show(Request $request, string $userId): ManagementUserResource
    {
        $environment = $this->context->environment($request);

        return new ManagementUserResource($this->context->user($environment, $userId));
    }
}
