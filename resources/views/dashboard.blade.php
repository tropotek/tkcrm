{{-- Placeholder until the dashboard is built (task 77), but for what is waiting in the review queue. --}}
@php($waiting = App\Review\ReviewQueue::count())

<x-layouts.app title="Dashboard">
    @if ($waiting > 0)
        <div class="card">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <div class="me-auto">
                    <h4 class="header-title mb-1">Waiting for review</h4>
                    <p class="mb-0">{{ $waiting === 1 ? '1 open invoice is' : $waiting.' open invoices are' }} waiting to be looked over and sent.</p>
                </div>
                <a href="{{ route('review.index') }}" class="btn btn-primary"><i class="ri-inbox-line me-1"></i>Review queue</a>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <p class="text-muted mb-0">Nothing more to show yet. Open tasks and income will appear here.</p>
        </div>
    </div>
</x-layouts.app>
