@extends('igniter.admin::layouts.default')

@section('title', 'Square Catalog Sync')

@section('toolbar')
    <div class="toolbar-action">
        <button
            class="btn btn-primary"
            data-request="onSave"
            data-request-validate
            data-progress-indicator="{{ lang('igniter::admin.text_saving') }}"
        >
            @lang('igniter::admin.button_save')
        </button>
        <a
            class="btn btn-default"
            data-request="onSyncNow"
            data-request-confirm="Queue a full sync from Square? This may take a moment."
            data-progress-indicator="Queueing…"
        >
            <i class="fa fa-rotate me-1"></i> Sync Now
        </a>
    </div>
@endsection

@section('content')
    <div class="container-fluid">

        {{-- Status card --}}
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><strong>Sync Status</strong></div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Last sync</dt>
                            <dd class="col-sm-9">{{ $syncAt ?? 'Never' }}</dd>

                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                @php
                                    $badge = match($syncStatus) {
                                        'success' => 'success',
                                        'failed'  => 'danger',
                                        'running' => 'warning',
                                        default   => 'secondary',
                                    };
                                @endphp
                                <span class="badge bg-{{ $badge }}">{{ ucfirst($syncStatus) }}</span>
                            </dd>

                            <dt class="col-sm-3">Objects synced</dt>
                            <dd class="col-sm-9">{{ number_format($syncCount) }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        {{-- Settings form --}}
        {!! form_open(['id' => 'edit-form', 'role' => 'form', 'method' => 'PATCH']) !!}
        @formWidget($formWidget)
        {!! form_close() !!}

        {{-- Recent log --}}
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><strong>Recent Log</strong> <small class="text-muted">(last 20 entries)</small></div>
                    <div class="card-body p-0">
                        @if($recentLogs->isEmpty())
                            <p class="p-3 mb-0 text-muted">No log entries yet.</p>
                        @else
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:160px">Time</th>
                                        <th style="width:80px">Level</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentLogs as $entry)
                                        <tr>
                                            <td class="text-muted small">{{ $entry->logged_at }}</td>
                                            <td>
                                                @php
                                                    $lvlBadge = match($entry->level) {
                                                        'error'   => 'danger',
                                                        'warning' => 'warning',
                                                        default   => 'secondary',
                                                    };
                                                @endphp
                                                <span class="badge bg-{{ $lvlBadge }}">{{ $entry->level }}</span>
                                            </td>
                                            <td>
                                                {{ $entry->message }}
                                                @if($entry->context)
                                                    <small class="text-muted d-block">{{ json_encode($entry->context) }}</small>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection
