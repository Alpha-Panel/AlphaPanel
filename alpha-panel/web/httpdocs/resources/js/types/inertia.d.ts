export interface AuthUser {
    id: number;
    name: string;
    username: string;
    email: string;
    is_admin: boolean;
    avatar_url?: string;
}

export interface FlashMessages {
    success: string | null;
    error: string | null;
    warning: string | null;
    info: string | null;
}

export interface SharedProps {
    auth: {
        user: AuthUser;
        permissions: string[];
        roles: string[];
    } | null;
    flash: FlashMessages;
    app: {
        name: string;
        logo_url?: string;
        links?: {
            file_manager?: string | null;
            jenkins?: string | null;
            n8n?: string | null;
        };
    };
    locale?: string;
    text_direction?: 'ltr' | 'rtl';
    available_locales?: string[];
    rtl_locales?: string[];
    translations?: Record<string, string>;
    vapid_public_key?: string;
}

export interface Domain {
    id: number;
    name: string;
    type: string;
    status: string;
    owner_user_id: number;
    parent_domain_id: number | null;
    created_at: string;
    updated_at: string;
}

export interface DnsRecord {
    id: number;
    domain_id: number;
    type: string;
    name: string;
    content: string;
    ttl: number;
    proxied: boolean;
}

export interface Database {
    id: number;
    name: string;
    domain_id: number;
    db_user: string;
    created_at: string;
}

export interface Pagination<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}
