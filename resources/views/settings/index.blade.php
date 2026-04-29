@extends('igniter.admin::layouts.default')

@section('toolbar')
    <div class="toolbar-action">
        <button
            class="btn btn-primary"
            data-request="onSave"
            data-progress-indicator="Saving&hellip;"
        >Save Settings</button>

        <button
            class="btn btn-default ms-2"
            data-request="onSyncNow"
            data-request-confirm="Queue a full sync from Square? Existing items will be upserted."
            data-progress-indicator="Queueing&hellip;"
        ><i class="fa fa-rotate me-1"></i>Sync Now</button>
    </div>
@endsection

@section('content')
<div class="container-fluid">

    {{-- Sync status card --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Sync Status</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Last sync</dt>
                        <dd class="col-sm-9">{{ $syncAt ?? '—' }}</dd>

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

                        <dt class="col-sm-3">Items synced</dt>
                        <dd class="col-sm-9">{{ number_format($syncCount) }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- Settings form --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">Connection Settings</div>
                <div class="card-body">
                    {!! form_open(['id' => 'squaresync-settings-form', 'role' => 'form', 'method' => 'POST']) !!}
                    @formWidget($formWidget)
                    {!! form_close() !!}
                </div>
            </div>
        </div>
    </div>

    {{-- Recent log --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">
                    Recent Log <small class="text-muted fw-normal">(last 20 entries)</small>
                </div>
                <div class="card-body p-0">
                    @if($recentLogs->isEmpty())
                        <p class="p-3 mb-0 text-muted">No log entries yet. Run a sync to see output here.</p>
                    @else
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:160px">Time</th>
                                    <th style="width:80px">Level</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentLogs as $entry)
                                    <tr>
                                        <td class="text-muted small">{{ $entry->logged_at->format('Y-m-d H:i:s') }}</td>
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
                                                <small class="text-muted d-block font-monospace">
                                                    {{ json_encode($entry->context) }}
                                                </small>
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
