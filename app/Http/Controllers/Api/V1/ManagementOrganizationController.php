<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Management\ManagementContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementOrganizationController extends Controller
{
    public function __construct(private readonly ManagementContext $context) {}

    public function show(Request $request): JsonResponse
    {
        $environment = $this->context->environment($request);
        $application = $environment->application;
        $organization = $application->tenant;

        return response()->json(['data' => [
            'id' => (string) $organization->getKey(),
            'name' => $organization->name,
            'slug' => $organization->slug,
            'application_id' => (string) $application->getKey(),
            'environment' => $environment->environment,
        ]]);
    }
}
