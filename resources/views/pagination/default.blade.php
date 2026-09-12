@if($paginator->hasPages())
<nav class="pagination" aria-label="Pagination">
@if($paginator->onFirstPage())<span aria-disabled="true">← Previous</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Previous</a>@endif
<span aria-current="page">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
@if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Next →</a>@else<span aria-disabled="true">Next →</span>@endif
</nav>
@endif
