<?php

namespace Workdo\SalesPurchase\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\ProductService\Exceptions\InsufficientStockException;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductTax;
use Workdo\ProductService\Models\Warehouse;
use Workdo\SalesPurchase\Exceptions\DocumentStateException;
use Workdo\SalesPurchase\Http\Requests\SaveDocumentRequest;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Services\DocumentCalculator;
use Workdo\SalesPurchase\Services\DocumentService;
use Workdo\SalesPurchase\Support\DocumentType;

/**
 * One controller for all five trade documents. The route default `type` (sales_invoice, ...) decides which one,
 * and DocumentType tells us labels, party role and permission slug (manage-sales-invoices, ...).
 */
class DocumentController extends Controller
{
    public function __construct(private DocumentService $documents, private DocumentCalculator $calculator)
    {
    }

    // ───────────────────────── reads ─────────────────────────

    public function index(Request $request, string $type): Response|RedirectResponse
    {
        if ($denied = $this->deny($type, 'manage')) {
            return $denied;
        }

        $meta = DocumentType::get($type);

        $documents = Document::with('party:id,name')
            ->ofType($type)
            ->where('created_by', creatorId())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($s) => $s->where('number', 'like', $term)->orWhereHas('party', fn ($p) => $p->where('name', 'like', $term)));
            })
            ->when(in_array($request->get('status'), $meta['statuses'], true), fn ($q) => $q->where('status', $request->get('status')))
            ->latest('doc_date')->latest('id')
            ->paginate((int) $request->get('per_page', 10))
            ->withQueryString();

        return Inertia::render('SalesPurchase/Documents/Index', [
            'documents' => $documents,
            'type' => $this->typeProps($type),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(Request $request, string $type): Response|RedirectResponse
    {
        if ($denied = $this->deny($type, 'create')) {
            return $denied;
        }

        $meta = DocumentType::get($type);

        if ($meta['is_return']) {
            $invoice = Document::with(['items.taxes', 'party:id,name', 'warehouse:id,name'])->ofType($meta['parent_type'])
                ->where('created_by', creatorId())->where('status', 'posted')->find($request->get('invoice'));

            if (!$invoice) {
                return redirect()->route(...$this->route($meta['parent_type'], 'index'))
                    ->with('error', __('Open a posted invoice and choose "Create return".'));
            }

            return Inertia::render('SalesPurchase/Documents/Form', [
                'type' => $this->typeProps($type),
                'invoice' => $invoice,
                'remaining' => $this->calculator->returnableQuantities($invoice),
            ]);
        }

        return Inertia::render('SalesPurchase/Documents/Form', ['type' => $this->typeProps($type)] + $this->formLookups($meta));
    }

    public function show(Document $document, string $type): Response|RedirectResponse
    {
        if ($denied = $this->deny($type, 'manage', $document)) {
            return $denied;
        }

        $document->load(['items.taxes', 'party:id,name,email', 'warehouse:id,name', 'parent:id,type,number', 'children:id,parent_id,type,number,status,total_amount']);

        return Inertia::render('SalesPurchase/Documents/Show', [
            'document' => $document,
            'type' => $this->typeProps($type),
            'can' => $this->abilities($document),
            'companyName' => companyOf(Auth::user())->name,
            'currencySymbol' => company_setting('currencySymbol', null, '$'),
        ]);
    }

    public function edit(Document $document, string $type): Response|RedirectResponse
    {
        if ($denied = $this->deny($type, 'edit', $document)) {
            return $denied;
        }

        $meta = DocumentType::get($type);
        if (!$document->isDraft() || $meta['is_return']) {
            return redirect()->route(...$this->route($type, 'show', $document))->with('error', __('Only draft documents can be edited.'));
        }

        return Inertia::render('SalesPurchase/Documents/Form', [
            'type' => $this->typeProps($type),
            'document' => $document->load('items.taxes'),
        ] + $this->formLookups($meta));
    }

    // ───────────────────────── writes ─────────────────────────

    public function store(SaveDocumentRequest $request, string $type): RedirectResponse
    {
        if ($denied = $this->deny($type, 'create')) {
            return $denied;
        }

        $meta = DocumentType::get($type);
        $tenant = creatorId();
        $data = $request->validated();

        if ($meta['is_return']) {
            $invoice = Document::where('created_by', $tenant)->findOrFail($data['parent_id']);
            $priced = $this->calculator->forReturn($invoice, $data['items']);
            $header = [
                'party_id' => $invoice->party_id, 'warehouse_id' => $invoice->warehouse_id, 'parent_id' => $invoice->id,
                'doc_date' => $data['doc_date'], 'notes' => $data['notes'] ?? null, 'reason' => $data['reason'] ?? null,
            ];
        } else {
            $priced = $this->calculator->forItems($data['items'], $tenant);
            $header = $this->header($data);
        }

        $document = $this->documents->create($type, $header, $priced, $tenant, Auth::id());

        return redirect()->route(...$this->route($type, 'show', $document))->with('success', __('The :doc has been created successfully.', ['doc' => strtolower(__($meta['label']))]));
    }

    public function update(SaveDocumentRequest $request, Document $document, string $type): RedirectResponse
    {
        if ($denied = $this->deny($type, 'edit', $document)) {
            return $denied;
        }
        if (DocumentType::isReturn($type)) {
            return back()->with('error', __('Returns cannot be edited. Delete the draft and create a new one.'));
        }

        $data = $request->validated();

        try {
            $this->documents->update($document, $this->header($data), $this->calculator->forItems($data['items'], creatorId()));
        } catch (DocumentStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route(...$this->route($type, 'show', $document))->with('success', __('The document is updated successfully.'));
    }

    public function destroy(Document $document, string $type): RedirectResponse
    {
        if ($denied = $this->deny($type, 'delete', $document)) {
            return $denied;
        }

        try {
            $this->documents->delete($document);
        } catch (DocumentStateException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route(...$this->route($type, 'index'))->with('success', __('The document has been deleted.'));
    }

    // ───────────────────────── state transitions ─────────────────────────

    public function post(Document $document, string $type): RedirectResponse
    {
        return $this->transition($type, $document, 'post', 'post', fn () => $this->documents->post($document), __('The invoice has been posted.'));
    }

    public function approve(Document $document, string $type): RedirectResponse
    {
        return $this->transition($type, $document, 'approve', 'approve', fn () => $this->documents->approveReturn($document), __('The return has been approved.'));
    }

    public function complete(Document $document, string $type): RedirectResponse
    {
        return $this->transition($type, $document, 'approve', 'complete', fn () => $this->documents->completeReturn($document), __('The return has been completed.'));
    }

    public function send(Document $document, string $type): RedirectResponse
    {
        return $this->transition($type, $document, 'edit', 'send', fn () => $this->documents->sendProposal($document), __('The proposal has been sent.'));
    }

    public function accept(Document $document, string $type): RedirectResponse
    {
        return $this->transition($type, $document, 'edit', 'accept', fn () => $this->documents->answerProposal($document, true), __('The proposal has been accepted.'));
    }

    public function reject(Document $document, string $type): RedirectResponse
    {
        return $this->transition($type, $document, 'edit', 'reject', fn () => $this->documents->answerProposal($document, false), __('The proposal has been rejected.'));
    }

    public function convert(Document $document, string $type): RedirectResponse
    {
        if (!Auth::user()->can('create-sales-invoices')) {
            return back()->with('error', __('Permission denied'));
        }

        $invoice = null;

        $response = $this->transition($type, $document, 'edit', 'convert', function () use ($document, &$invoice) {
            $invoice = $this->documents->convertProposal($document, Auth::id());
        }, __('The proposal has been converted into a draft invoice.'));

        return $invoice
            ? redirect()->route(...$this->route(DocumentType::SALES_INVOICE, 'show', $invoice))->with('success', __('The proposal has been converted into a draft invoice.'))
            : $response;
    }

    // ───────────────────────── helpers ─────────────────────────

    /** Common wrapper: permission + tenant check, run the service call, turn domain errors into flash messages. */
    private function transition(string $type, Document $document, string $permission, string $action, callable $run, string $success): RedirectResponse
    {
        if ($denied = $this->deny($type, $permission, $document)) {
            return $denied;
        }

        try {
            $run();
        } catch (DocumentStateException|InsufficientStockException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /** null when allowed; otherwise the redirect to send the user to. */
    private function deny(string $type, string $verb, ?Document $document = null): ?RedirectResponse
    {
        $slug = DocumentType::get($type)['slug'];

        $allowed = Auth::user()->can("{$verb}-{$slug}")
            && (!$document || ($document->created_by === creatorId() && $document->type === $type));

        if ($allowed) {
            return null;
        }

        return $verb === 'manage'
            ? redirect()->route('dashboard')->with('error', __('Permission denied'))
            : back()->with('error', __('Permission denied'));
    }

    /** What the current user may do with this document right now (drives the buttons on the Show page). */
    private function abilities(Document $document): array
    {
        $user = Auth::user();
        $meta = DocumentType::get($document->type);
        $slug = $meta['slug'];
        $draft = $document->isDraft();
        $invoice = in_array($document->type, [DocumentType::SALES_INVOICE, DocumentType::PURCHASE_INVOICE], true);

        return [
            'edit' => $user->can("edit-{$slug}") && $draft && !$meta['is_return'],
            'delete' => $user->can("delete-{$slug}") && $draft,
            'post' => $invoice && $draft && $user->can("post-{$slug}"),
            'approve' => $meta['is_return'] && $draft && $user->can("approve-{$slug}"),
            'complete' => $meta['is_return'] && $document->status === 'approved' && $user->can("approve-{$slug}"),
            'send' => $document->type === DocumentType::SALES_PROPOSAL && $draft && $user->can("edit-{$slug}"),
            'answer' => $document->type === DocumentType::SALES_PROPOSAL && $document->status === 'sent' && $user->can("edit-{$slug}"),
            'convert' => $document->type === DocumentType::SALES_PROPOSAL && $document->status === 'accepted'
                && $user->can("edit-{$slug}") && $user->can('create-sales-invoices'),
            'createReturn' => $invoice && $document->status === 'posted'
                && $user->can('create-' . ($document->type === DocumentType::SALES_INVOICE ? 'sales-returns' : 'purchase-returns')),
            'returnType' => $document->type === DocumentType::SALES_INVOICE ? DocumentType::SALES_RETURN : DocumentType::PURCHASE_RETURN,
        ];
    }

    private function header(array $data): array
    {
        return [
            'party_id' => $data['party_id'],
            'warehouse_id' => $data['warehouse_id'],
            'doc_date' => $data['doc_date'],
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function typeProps(string $type): array
    {
        $meta = DocumentType::get($type);

        return [
            'key' => $type,
            'label' => $meta['label'],
            'plural' => $meta['plural'],
            'slug' => $meta['slug'],
            'party' => $meta['party'],
            'partyLabel' => $meta['party_label'],
            'isReturn' => $meta['is_return'],
            'statuses' => $meta['statuses'],
            'routeBase' => 'salespurchase.' . $meta['slug'],
        ];
    }

    /** Dropdown data for the document form: parties, warehouses, products and taxes of the company. */
    private function formLookups(array $meta): array
    {
        $tenant = creatorId();

        return [
            'parties' => User::where('created_by', $tenant)->where('type', $meta['party'])->orderBy('name')->get(['id', 'name', 'email']),
            'warehouses' => Warehouse::where('created_by', $tenant)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('created_by', $tenant)->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'sku', 'type', 'sale_price', 'purchase_price', 'tax_ids']),
            'taxes' => ProductTax::where('created_by', $tenant)->orderBy('name')->get(['id', 'name', 'rate']),
        ];
    }

    /** route() arguments for a document type: [name, params]. */
    private function route(string $type, string $action, ?Document $document = null): array
    {
        $name = 'salespurchase.' . DocumentType::get($type)['slug'] . '.' . $action;

        return $document ? [$name, $document->id] : [$name];
    }
}
