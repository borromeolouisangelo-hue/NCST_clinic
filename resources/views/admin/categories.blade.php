{{--
    Admin - Medicine categories - exact port of admin/categories.php
    (original lines 76-167): the category modal (card header ternary, error
    loop, type/name/description fields, save button + conditional Cancel -
    rendered CLOSED on ?action=edit like the reference, which has no reopen
    script for it), the GET toolbar (search input, type select reading the
    RAW query for its selected-state, Search/Reset/Add buttons), the table
    (med_count subquery, per-row type badge and edit/delete links) and the
    page-specific shell closers.

    Emission notes:
    - $errors of the reference is $categoryErrors here (Laravel always
      injects its own $errors bag into views); failed creates render the
      loop with $edit null (fields go blank - the reference never
      repopulates posted values), failed edits reload the row from the DB.
    - The form carries no token in the reference and the page runs no CSRF
    check; it carries @csrf here so it saves under the global middleware
    (approved Phase 6 strengthening). The blank line after @csrf compensates
    PHP eating one newline after the compiled field line; full_diff strips
    the hidden token input itself.
    - The save button's data-swal attribute ternaries were RAW echoes in the
    reference (quotes intact), so they stay raw here - Blade {{ }} would
    htmlspecialchars the quotes and change the DOM attribute value.
    - The reference's type <select> submits ?type=... but its SQL never
    filters on it; only the selected-state reads the raw value (kept).
    - The closers stay inside the view before endsection: the reference page
    closes the shell itself before requiring layout_dashboard_end.php.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div class="row g-3">
                <div class="col-12">
                    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content">
                        <div class="card">
                            <div class="card-header" id="categoryModalLabel">{{ $edit ? 'Edit Category' : 'Add Category' }}</div>
                            <div class="card-body">
                                @foreach ($categoryErrors as $err)
                                    <div class="alert alert-danger py-2">{{ $err }}</div>
                                @endforeach
                                <form method="post">
                                    @csrf

                                    <input type="hidden" name="id" value="{{ (int)($edit['id'] ?? 0) }}">
                                    <div class="mb-3">
                                        <label class="form-label">Type</label>
                                        <select name="type" class="form-select">
                                            <option value="medicine" {{ (($edit['type'] ?? 'medicine') === 'medicine') ? 'selected' : '' }}>Medicine</option>
                                            <option value="first_aid" {{ (($edit['type'] ?? 'medicine') === 'first_aid') ? 'selected' : '' }}>First Aid / Wound Care</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Name *</label>
                                        <input type="text" name="name" class="form-control" value="{{ $edit['name'] ?? '' }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-control" rows="2">{{ $edit['description'] ?? '' }}</textarea>
                                    </div>
                                    <button class="btn btn-primary" {!! $edit ? 'data-swal-save="category"' : 'data-swal-add="category"' !!}><i class="bi bi-save me-1"></i> {!! $edit ? 'Update' : 'Save' !!}</button>
                                    @if ($edit)<a href="{{ url('admin/categories') }}" class="btn btn-outline-secondary">Cancel</a>@endif
                                </form>
                            </div>
                        </div>
                        </div></div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-body">
                            <form method="get" class="row g-2 align-items-end audit-toolbar">
                                <div class="col">
                                    <label class="form-label">Search</label>
                                    <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="Name, type or description" aria-label="Search categories">
                                </div>
                                <div class="col-auto">
                                    <label class="form-label">Filter</label>
                                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="">All Types</option>
                                        <option value="medicine" {{ $rawType === 'medicine' ? 'selected' : '' }}>Medicine</option>
                                        <option value="first_aid" {{ $rawType === 'first_aid' ? 'selected' : '' }}>First Aid / Wound Care</option>
                                    </select>
                                </div>
                                <div class="col d-flex flex-wrap gap-2 justify-content-start">
                                    <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                                    <a href="{{ url('admin/categories') }}" class="btn btn-sm btn-outline-secondary text-nowrap">Reset</a>
                                    <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#categoryModal"><i class="bi bi-plus-lg me-1"></i> Add Category</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead><tr><th>#</th><th>Type</th><th>Name</th><th>Description</th><th class="text-end">Items</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            @if (!$cats)
                                <tr><td colspan="6" class="text-center text-muted py-4">No categories yet.</td></tr>
                            @else
@foreach ($cats as $c)
                                <tr>
                                    <td>{{ (int)$c['id'] }}</td>
                                    <td><span class="badge bg-secondary">{{ $c['type'] === 'first_aid' ? 'First Aid / Wound Care' : 'Medicine' }}</span></td>
                                    <td>{{ $c['name'] }}</td>
                                    <td>{{ $c['description'] }}</td>
                                    <td class="text-end">{{ (int)$c['med_count'] }}</td>
                                    <td class="text-end">
                                        <a href="{{ url('admin/categories?action=edit&id=' . $c['id']) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <a href="{{ url('admin/categories?action=delete&id=' . $c['id']) }}" class="btn btn-sm btn-outline-danger" data-confirm="Delete this category?" data-swal-delete="category"><i class="bi bi-trash"></i></a>
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
