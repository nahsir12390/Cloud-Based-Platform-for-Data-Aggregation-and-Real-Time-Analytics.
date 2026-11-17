@if($paginator->hasPages())
<nav aria-label="Event pagination">
<button type="button" class="text-button" wire:click="previousPage" wire:loading.attr="disabled" @disabled($paginator->onFirstPage())>← Previous</button>
<span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
<button type="button" class="text-button" wire:click="nextPage" wire:loading.attr="disabled" @disabled(!$paginator->hasMorePages())>Next →</button>
</nav>
@endif
