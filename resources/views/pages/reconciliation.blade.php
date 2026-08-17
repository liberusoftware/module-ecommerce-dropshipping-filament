{{-- A table on a page rather than a resource, because there is no column behind
     it: "this purchase is unconfirmed" is the answer to replaying its events in
     order, and it is not expressible as a query. --}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
