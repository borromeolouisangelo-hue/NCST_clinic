{{--
    Admin - Suppliers - exact port of admin/suppliers.php (original lines
    66-153): the supplier modal (card header ternary, error loop,
    name/contact/phone/email/address fields, save button + conditional Cancel
    - rendered CLOSED on ?action=edit like the reference), the GET toolbar
    (search input, Search + Add Supplier buttons - no filter select on this
    page), the table (name/contact/phone/email cells, edit/delete links) and
    the page-specific shell closers.

    Emission notes:
    - $errors of the reference is $supplierErrors here (Laravel always
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
    - The closers stay inside the view before endsection: the reference page
      closes the shell itself before requiring layout_dashboard_end.php.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div class="row g-3">
                <div class="col-12">
                    <div class="modal fade" id="supplierModal" tabindex="-1" aria-labelledby="supplierModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content">
                        <div class="card">
                            <div class="card-header" id="supplierModalLabel">{{ $edit ? 'Edit Supplier' : 'Add Supplier' }}</div>
                            <div class="card-body">
                                @foreach ($supplierErrors as $err)
                                    <div class="alert alert-danger py-2">{{ $err }}</div>
                                @endforeach
                                <form method="post">
                                    @csrf

                                    <input type="hidden" name="id" value="{{ (int)($edit['id'] ?? 0) }}">
                                    <div class="mb-3">
                                        <label class="form-label">Name *</label>
                                        <input type="text" name="name" class="form-control" value="{{ $edit['name'] ?? '' }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Contact Person</label>
                                        <input type="text" name="contact_person" class="form-control" value="{{ $edit['contact_person'] ?? '' }}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Phone</label>
                                        <input type="text" name="phone" class="form-control" value="{{ $edit['phone'] ?? '' }}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="{{ $edit['email'] ?? '' }}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" class="form-control" rows="2">{{ $edit['address'] ?? '' }}</textarea>
                                    </div>
                                    <button class="btn btn-primary" {!! $edit ? 'data-swal-save="supplier"' : 'data-swal-add="supplier"' !!}><i class="bi bi-save me-1"></i> {!! $edit ? 'Update' : 'Save' !!}</button>
                                    @if ($edit)<a href="{{ url('admin/suppliers') }}" class="btn btn-outline-secondary">Cancel</a>@endif
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
                                    <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="Name, contact, phone or email" aria-label="Search suppliers">
                                </div>
                                <div class="col d-flex flex-wrap gap-2 justify-content-start">
                                    <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                                    <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#supplierModal"><i class="bi bi-plus-lg me-1"></i> Add Supplier</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead><tr><th>#</th><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            @if (!$suppliers)
                                <tr><td colspan="6" class="text-center text-muted py-4">No suppliers yet.</td></tr>
                            @else
@foreach ($suppliers as $s)
                                <tr>
                                    <td>{{ (int)$s['id'] }}</td>
                                    <td>{{ $s['name'] }}</td>
                                    <td>{{ $s['contact_person'] }}</td>
                                    <td>{{ $s['phone'] }}</td>
                                    <td>{{ $s['email'] }}</td>
                                    <td class="text-end">
                                        <a href="{{ url('admin/suppliers?action=edit&id=' . $s['id']) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                        <a href="{{ url('admin/suppliers?action=delete&id=' . $s['id']) }}" class="btn btn-sm btn-outline-danger" data-confirm="Delete this supplier?" data-swal-delete="supplier"><i class="bi bi-trash"></i></a>
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
