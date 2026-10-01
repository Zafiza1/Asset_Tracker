<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Http\Resources\TemplateVersionResource;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Template Controller — manages platform templates (Control Plane).
 * Templates are global resources, not tenant-specific.
 */
class TemplateController extends Controller
{
    public function __construct(
        protected TemplateService $templateService
    ) {
    }

    /**
     * List all available templates.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Template::class);

        $query = Template::available();

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('include_deprecated')) {
            $query->withoutGlobalScopes();
        }

        $templates = $query->with('currentVersion')->get();

        return TemplateResource::collection($templates);
    }

    /**
     * Show a specific template.
     */
    public function show(Template $template): TemplateResource
    {
        $this->authorize('view', $template);

        $template->load(['currentVersion', 'versions' => function ($query) {
            $query->latest();
        }]);

        return new TemplateResource($template);
    }

    /**
     * Create a new template.
     */
    public function store(StoreTemplateRequest $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('create', Template::class);

        $template = $this->templateService->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => new TemplateResource($template->load('currentVersion')),
        ], 201);
    }

    /**
     * Update template metadata.
     */
    public function update(UpdateTemplateRequest $request, Template $template): TemplateResource
    {
        $this->authorize('update', $template);

        $template = $this->templateService->update($template, $request->validated());

        return new TemplateResource($template->load('currentVersion'));
    }

    /**
     * Delete a template.
     */
    public function destroy(Template $template): \Illuminate\Http\JsonResponse
    {
        $this->authorize('delete', $template);

        $this->templateService->delete($template);

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully',
        ]);
    }

    /**
     * Create a new version for a template.
     */
    public function createVersion(Request $request, Template $template): TemplateVersionResource
    {
        $this->authorize('manageVersions', $template);

        $request->validate([
            'version' => 'required|string',
            'description' => 'nullable|string',
            'default_modules' => 'nullable|array',
            'default_settings' => 'nullable|array',
            'metadata' => 'nullable|array',
            'status' => 'nullable|in:available,deprecated',
            'released_at' => 'nullable|date',
            'modules' => 'nullable|array',
            'modules.*.module_slug' => 'required|string|exists:modules,slug',
            'modules.*.version_constraint' => 'nullable|string',
            'modules.*.required' => 'nullable|boolean',
            'modules.*.default_config' => 'nullable|array',
            'modules.*.sort_order' => 'nullable|integer',
        ]);

        $version = $this->templateService->createVersion($template, $request->all());

        return new TemplateVersionResource($version->load('modules'));
    }

    /**
     * Set the current version for a template.
     */
    public function setCurrentVersion(Request $request, Template $template): TemplateResource
    {
        $this->authorize('manageVersions', $template);

        $request->validate([
            'version_id' => 'required|exists:template_versions,id',
        ]);

        $version = TemplateVersion::findOrFail($request->version_id);
        $template = $this->templateService->setCurrentVersion($template, $version);

        return new TemplateResource($template->load('currentVersion'));
    }

    /**
     * List versions for a template.
     */
    public function versions(Template $template): AnonymousResourceCollection
    {
        $this->authorize('view', $template);

        $versions = $template->versions()->latest()->get();

        return TemplateVersionResource::collection($versions);
    }

    /**
     * Show a specific template version.
     */
    public function showVersion(Template $template, TemplateVersion $version): TemplateVersionResource
    {
        $this->authorize('view', $template);

        if ($version->template_id !== $template->id) {
            return response()->json([
                'success' => false,
                'message' => 'Version does not belong to this template',
            ], 404);
        }

        $version->load('modules');

        return new TemplateVersionResource($version);
    }

    /**
     * Associate a module with a template version.
     */
    public function associateModule(Request $request, Template $template, TemplateVersion $version): \Illuminate\Http\JsonResponse
    {
        $this->authorize('manageModules', $template);

        if ($version->template_id !== $template->id) {
            return response()->json([
                'success' => false,
                'message' => 'Version does not belong to this template',
            ], 404);
        }

        $request->validate([
            'module_slug' => 'required|string|exists:modules,slug',
            'version_constraint' => 'nullable|string',
            'required' => 'nullable|boolean',
            'default_config' => 'nullable|array',
            'sort_order' => 'nullable|integer',
        ]);

        $templateModule = $this->templateService->associateModuleToVersion($version, $request->all());

        return response()->json([
            'success' => true,
            'data' => $templateModule,
            'message' => 'Module associated successfully',
        ]);
    }

    /**
     * Remove a module association from a template version.
     */
    public function dissociateModule(Request $request, Template $template, TemplateVersion $version): \Illuminate\Http\JsonResponse
    {
        $this->authorize('manageModules', $template);

        if ($version->template_id !== $template->id) {
            return response()->json([
                'success' => false,
                'message' => 'Version does not belong to this template',
            ], 404);
        }

        $request->validate([
            'module_slug' => 'required|string|exists:modules,slug',
        ]);

        $this->templateService->dissociateModule($version, $request->module_slug);

        return response()->json([
            'success' => true,
            'message' => 'Module dissociated successfully',
        ]);
    }

    /**
     * Deprecate a template.
     */
    public function deprecate(Template $template): TemplateResource
    {
        $this->authorize('deprecate', $template);

        $template = $this->templateService->deprecate($template);

        return new TemplateResource($template);
    }
}
