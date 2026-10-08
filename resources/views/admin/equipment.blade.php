{{--
    Admin Equipment - exact port of admin/equipment.php (original lines
    78-182): the add/edit modal, the search/filter toolbar, and the
    equipment table.

    Approved differences: links drop .php and the Reset/Cancel targets use
    route('admin.equipment') (Phase 4 convention); the modal form carries
    @csrf although the reference has no token field at all (global CSRF
    middleware since Phase 6 - approved strengthening) and the blank line
    after @csrf compensates PHP eating one newline after the compiled
    directive, so the hidden id input keeps the original's exact leading
    whitespace; row keys use -> instead of ['x'] (DB::select stdClass
    rows, same values); success flashes are written but never rendered
    (write-only legacy slot - see partials/flash.blade.php).

    Legacy quirks kept: ?condition= echoes into the toolbar select but
    NEVER filters the list (only ?q= applies); ?action=delete performs no
    existence check - an unknown id still audits, flashes success and
    redirects; ?action=edit with a missing id renders the ADD modal; the
    update recomputes available_qty as total minus the previously borrowed
    units; the page itself closes the shell tags (layout end repeats the
    include's own closing stack exactly like the reference).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div class="row g-3">
                <div class="col-12">
                    <div class="modal fade" id="equipmentModal" tabindex="-1" aria-labelledby="equipmentModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content">
                        <div class="card">
                            <div class="card-header" id="equipmentModalLabel">{{ $edit ? 'Edit Equipment' : 'Add Equipment' }}</div>
                            <div class="card-body">
                                @foreach ($errors as $err)
                                    <div class="alert alert-danger py-2">{{ $err }}</div>
                                @endforeach
                                <form method="post">
@csrf

                                    <input type="hidden" name="id" value="{{ (int) ($edit->id ?? 0) }}">
                                    <div class="mb-3">
                                        <label class="form-label">Name *</label>
                                        <input type="text" name="name" class="form-control" value="{{ $edit->name ?? '' }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="2">{{ $edit->description ?? '' }}</textarea>
                                    </div>
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <label class="form-label">Total Qty</label>
                                            <input type="number" name="total_qty" class="form-control" min="0" value="{{ (int) ($edit->total_qty ?? 1) }}">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Condition</label>
                                            <input type="text" name="condition_note" class="form-control" value="{{ $edit->condition_note ?? 'good' }}">
                                        </div>
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" {{ (int) ($edit->is_active ?? 1) === 1 ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                    <button class="btn btn-primary" {!! $edit ? 'data-swal-save="equipment"' : 'data-swal-add="equipment"' !!}><i class="bi bi-save me-1"></i> {{ $edit ? 'Update' : 'Save' }}</button>
                                    @if ($edit)<a href="{{ route('admin.equipment') }}" class="btn btn-outline-secondary">Cancel</a>@endif
                                </form>
                            </div>
                        </div>
                        </div></div>
                    </div>
                </div>
                <div class="col">
                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="get" class="row g-2 align-items-end audit-toolbar">
                                <div class="col">
                                    <label class="form-label">Search</label>
                                    <input type="search" name="q" class="form-control form-control-sm" value="{{ $q }}" placeholder="Name, description or condition" aria-label="Search equipment">
                                </div>
                                <div class="col-auto">
                                    <label class="form-label">Filter</label>
                                    <select name="condition" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="">All Conditions</option>
                                        <option value="good" {{ $condition === 'good' ? 'selected' : '' }}>Good</option>
                                        <option value="fair" {{ $condition === 'fair' ? 'selected' : '' }}>Fair</option>
                                        <option value="poor" {{ $condition === 'poor' ? 'selected' : '' }}>Poor</option>
                                    </select>
                                </div>
                                <div class="col d-flex flex-wrap gap-2 justify-content-start">
                                    <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                                    <a href="{{ route('admin.equipment') }}" class="btn btn-sm btn-outline-secondary text-nowrap">Reset</a>
                                    <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#equipmentModal"><i class="bi bi-plus-lg me-1"></i> Add Equipment</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead><tr><th>#</th><th>Name</th><th>Description</th><th class="text-end">Total</th><th class="text-end">Available</th><th>Condition</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            @if (!$equipment)
                                <tr><td colspan="8" class="text-center text-muted py-4">No equipment yet.</td></tr>
                            @else
@foreach ($equipment as $eq)
                                <tr>
                                    <td>{{ (int) $eq->id }}</td>
                                    <td>{{ $eq->name }}</td>
                                    <td>{{ $eq->description }}</td>
                                    <td class="text-end">{{ (int) $eq->total_qty }}</td>
                                    <td class="text-end">
                                        <span class="badge {{ (int) $eq->available_qty > 0 ? 'bg-success' : 'bg-danger' }}">{{ (int) $eq->available_qty }}</span>
                                    </td>
                                    <td>{{ $eq->condition_note }}</td>
                                    <td>{!! (int) $eq->is_active === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
                                    <td class="text-end">
                                        <a href="{{ url('admin/equipment?action=edit&id=' . $eq->id) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <a href="{{ url('admin/equipment?action=delete&id=' . $eq->id) }}" class="btn btn-sm btn-outline-danger" data-confirm="Delete this equipment?" data-swal-delete="equipment"><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                            @endforeach
@endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- /.content-area -->
    </main>
    <!-- /.main-content -->
</div>
<!-- /.app-wrapper -->

@endsection
