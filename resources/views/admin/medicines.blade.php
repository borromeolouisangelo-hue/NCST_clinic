{{--
    Admin Medicines - exact port of admin/medicines.php (original lines 96-206):
    the Add/Edit medicine modal, the search toolbar, and the catalog table.

    Approved differences: action URLs drop .php (routing convention since
    Phase 4); the modal form carries @csrf instead of csrf_token (Phase 6) -
    the blank line after @csrf compensates PHP eating one newline after the
    compiled directive, so the hidden id input keeps the original's exact
    leading whitespace; $m['x'] / $edit['x'] become ->x (stdClass rows, same
    values); POST CSRF failure raises Laravel's 419 instead of flash+redirect
    (Phase 6); the success flashes are written but never rendered (write-only
    legacy slot - see partials/flash.blade.php).

    Legacy quirks kept: NO auto-open script on validation errors (the modal
    stays closed and the errors render inside it); a failed POST to
    ?action=edit re-renders the edit context from the query string; delete and
    edit run off GET with no existence checks (a missing id still flashes
    success); an empty unit falls back to 'tablet' without erroring; the
    shell-close block (199-205) is emitted exactly like the original before
    the shared layout closers.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div class="row g-3">
                <div class="col-12">
                    <div class="modal fade" id="medicineModal" tabindex="-1" aria-labelledby="medicineModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content">
                        <div class="card">
                            <div class="card-header" id="medicineModalLabel">{{ $edit ? 'Edit Medicine' : 'Add Medicine' }}</div>
                            <div class="card-body">
                                @foreach ($errors as $err)
                                    <div class="alert alert-danger py-2">{{ $err }}</div>
                                @endforeach
                                <form method="post">
                                    <input type="hidden" name="id" value="{{ (int) ($edit->id ?? 0) }}">
                                    <div class="mb-3">
                                        <label class="form-label">Brand / Name *</label>
                                        <input type="text" name="name" class="form-control" value="{{ $edit->name ?? '' }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Generic Name</label>
                                        <input type="text" name="generic_name" class="form-control" value="{{ $edit->generic_name ?? '' }}">
                                        <div class="form-text">Used for allergy matching.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Category</label>
                                        <select name="category_id" class="form-select">
                                            <option value="">-- none --</option>
                                            @foreach ($categories as $c)
                                                <option value="{{ (int) $c->id }}" {{ (int) ($edit->category_id ?? 0) === (int) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <label class="form-label">Unit</label>
                                            <input type="text" name="unit" class="form-control" value="{{ $edit->unit ?? 'tablet' }}">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label">Low Stock Threshold</label>
                                            <input type="number" name="low_stock_threshold" class="form-control" min="0" value="{{ (int) ($edit->low_stock_threshold ?? 10) }}">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="2">{{ $edit->description ?? '' }}</textarea>
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" {{ (int) ($edit->is_active ?? 1) === 1 ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                    @csrf

                                    <button class="btn btn-primary" {!! $edit ? 'data-swal-save="medicine"' : 'data-swal-add="medicine"' !!}><i class="bi bi-save me-1"></i> {{ $edit ? 'Update' : 'Save' }}</button>
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
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
                                    <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="Brand or generic name" aria-label="Search medicine catalog">
                                </div>
                                <div class="col d-flex flex-wrap gap-2 justify-content-start">
                                    <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                                    <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#medicineModal"><i class="bi bi-plus-lg me-1"></i> Add Medicine</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table id="medicinesTable" class="table table-hover mb-0 align-middle">
                            <thead><tr><th>#</th><th>Name</th><th>Generic</th><th>Category</th><th>Unit</th><th class="text-end">Stock</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            @if (!$medicines)
                                <tr><td colspan="8" class="text-center text-muted py-4">No medicines found.</td></tr>
                            @else
@foreach ($medicines as $m)
                                <tr>
                                    <td>{{ (int) $m->id }}</td>
                                    <td>{{ $m->name }}</td>
                                    <td>{{ $m->generic_name }}</td>
                                    <td>{{ $m->category_name ?: '—' }}</td>
                                    <td>{{ $m->unit }}</td>
                                    <td class="text-end">
                                        @php $st = (int) $m->stock; @endphp
                                        <span class="badge {{ $st <= (int) $m->low_stock_threshold ? 'bg-danger' : 'bg-success' }}">{{ $st }}</span>
                                    </td>
                                    <td>{!! $m->is_active === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
                                    <td class="text-end">
                                        <a href="{{ url('admin/medicines?action=edit&id=' . $m->id) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <a href="{{ url('admin/medicines?action=delete&id=' . $m->id) }}" class="btn btn-sm btn-outline-danger" data-confirm="Delete this medicine and all its batches?" data-swal-delete="medicine"><i class="bi bi-trash"></i></a>
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
