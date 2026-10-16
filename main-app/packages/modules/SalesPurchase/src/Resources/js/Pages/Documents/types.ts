/** Shapes shared by the Documents pages (Index / Form / Show). */

export interface TypeProps {
    key: string;
    label: string;
    plural: string;
    slug: string;
    party: 'client' | 'vendor';
    partyLabel: string;
    isReturn: boolean;
    statuses: string[];
    routeBase: string; // e.g. salespurchase.sales-invoices
}

export interface DocItemTax {
    id: number;
    tax_id: number | null;
    name: string;
    rate: number;
    amount: number;
}

export interface DocItem {
    id: number;
    product_id: number;
    name: string;
    quantity: number;
    unit_price: number;
    discount_amount: number;
    tax_amount: number;
    total_amount: number;
    taxes: DocItemTax[];
}

export interface DocumentRow {
    id: number;
    type: string;
    number: string;
    party_id: number;
    warehouse_id: number;
    parent_id: number | null;
    doc_date: string;
    due_date: string | null;
    status: string;
    subtotal: number;
    discount_amount: number;
    tax_amount: number;
    total_amount: number;
    paid_amount: number;
    notes: string | null;
    reason: string | null;
    party?: { id: number; name: string; email?: string };
    warehouse?: { id: number; name: string };
    items?: DocItem[];
    parent?: { id: number; type: string; number: string } | null;
    children?: { id: number; parent_id: number; type: string; number: string; status: string; total_amount: number }[];
}

export interface ProductOption {
    id: number;
    name: string;
    sku: string;
    type: 'product' | 'service';
    sale_price: number;
    purchase_price: number;
    tax_ids: number[] | null;
}

export interface TaxOption {
    id: number;
    name: string;
    rate: number;
}

/** What the Show page may offer to the current user (computed on the server). */
export interface Abilities {
    edit: boolean;
    delete: boolean;
    post: boolean;
    approve: boolean;
    complete: boolean;
    send: boolean;
    answer: boolean;
    convert: boolean;
    createReturn: boolean;
    returnType: string;
}

export const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'secondary',
    sent: 'outline',
    accepted: 'default',
    rejected: 'destructive',
    converted: 'default',
    posted: 'default',
    approved: 'outline',
    completed: 'default',
};

/** URL slug of a document type key, e.g. sales_invoice -> sales-invoices (used for links between documents). */
export const SLUG_BY_TYPE: Record<string, string> = {
    sales_invoice: 'sales-invoices',
    purchase_invoice: 'purchase-invoices',
    sales_proposal: 'sales-proposals',
    sales_return: 'sales-returns',
    purchase_return: 'purchase-returns',
};
