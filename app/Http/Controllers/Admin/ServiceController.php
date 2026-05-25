<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index()
    {
        return Service::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:services,slug',
            'description' => 'nullable|string',
            'form_schema' => 'nullable|array',
            'requires_admin_approval' => 'boolean',
        ]);

        $service = Service::create($request->all());
        return response()->json($service, 201);
    }

    public function show(Service $service)
    {
        return response()-> 
        Service::find($service); // Gunakan Service::find() untuk show
    }

    public function update(Request $request, Service $service)
    {
        $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:services,slug,' . $service->id,
            'description' => 'nullable|string',
            'form_schema' => 'nullable|array',
            'requires_admin_approval' => 'boolean',
        ]);

        $service->update($request->all());
        return response()->json($service);
    }

    public function destroy(Service $service)
    {
        $service->delete();
        return response()->json(['message' => 'Layanan berhasil dihapus']);
    }
}