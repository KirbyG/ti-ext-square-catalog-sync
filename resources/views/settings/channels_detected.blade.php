<div class="card shadow-sm">
    <div class="card-header fw-semibold">
        Detected Channels
        <small class="text-muted fw-normal ms-2">{{ number_format($itemsScanned) }} items scanned</small>
    </div>

    @if(empty($channelCounts))
        <div class="card-body">
            <p class="mb-0 text-muted">No channel IDs found on any items. Check that your catalog contains items with channel assignments.</p>
        </div>
    @else
        @php $minCount = min(array_values($channelCounts)); @endphp
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Channel ID</th>
                        <th class="text-end" style="width:100px">Items</th>
                        <th style="width:180px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($channelCounts as $channelId => $count)
                        <tr @class(['table-success' => $channelId === $currentChannelId])>
                            <td class="font-monospace small">
                                {{ $channelId }}
                                @if($count === $minCount && count($channelCounts) > 1)
                                    <span class="badge bg-info ms-1">likely online ordering</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($count) }}</td>
                            <td>
                                @if($channelId === $currentChannelId)
                                    <span class="text-success small"><i class="fa fa-check me-1"></i>Currently set</span>
                                @else
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        onclick="document.querySelector('[name=\'Settings[ordering_channel_id]\']').value = '{{ $channelId }}'; this.closest('table').querySelectorAll('tr').forEach(r => r.classList.remove('table-success')); this.closest('tr').classList.add('table-success'); this.outerHTML = '<span class=\'text-success small\'><i class=\'fa fa-check me-1\'></i>Selected — click Save Settings</span>';"
                                    >Use this</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small">
            The channel with the fewest items is usually the online ordering channel — POS channels appear on all items.
        </div>
    @endif
</div>
