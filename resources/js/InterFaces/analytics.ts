// resources/js/InterFaces/analytics.ts

export interface SellerStats {
    seller_id: number;
    seller_username: string;
    seller_email: string;
    
    // Nick stats - từ bảng nicks
    nick_total_count: number;
    nick_total_revenue: number;
    nick_total_commission: number;
    
    // Nick theo status
    nick_sold_count: number;
    nick_sold_revenue: number;
    nick_deleted_count: number;
    nick_deleted_amount: number;
    nick_returned_count: number;
    nick_returned_amount: number;
    nick_available_count: number;
    nick_available_value: number;
    
    // Service stats
    service_total_count: number;
    service_total_revenue: number;
    service_completed_count: number;
    service_completed_revenue: number;
    service_rejected_count: number;
    service_rejected_revenue: number;
    service_pending_count: number;
    service_approved_count: number;
    
    total_revenue: number;
}

export interface CategoryStats {
    category_id: number;
    category_name: string;
    category_slug: string;
    totals: {
        // Nick stats
        nick_total_count: number;
        nick_total_revenue: number;
        nick_total_commission: number;
        
        // Nick theo status  
        nick_sold_count: number;
        nick_sold_revenue: number;
        nick_deleted_count: number;
        nick_deleted_amount: number;
        nick_returned_count: number;
        nick_returned_amount: number;
        nick_available_count: number;
        nick_available_value: number;
        
        // Service stats
        service_total_count: number;
        service_total_revenue: number;
        service_completed_count: number;
        service_completed_revenue: number;
        service_rejected_count: number;
        service_rejected_revenue: number;
        service_pending_count: number;
        service_approved_count: number;
        
        total_revenue: number;
    };
    sellers: SellerStats[];
}

export interface SellerCategoryStats {
    category_id: number;
    category_name: string;
    category_slug: string;
    
    // Nick stats
    nick_total_count: number;
    nick_total_revenue: number;
    nick_total_commission: number;
    
    // Nick theo status
    nick_sold_count: number;
    nick_sold_revenue: number;
    nick_deleted_count: number;
    nick_deleted_amount: number;
    nick_returned_count: number;
    nick_returned_amount: number;
    nick_available_count: number;
    nick_available_value: number;
    
    // Service stats
    service_total_count: number;
    service_total_revenue: number;
    service_completed_count: number;
    service_completed_revenue: number;
    service_rejected_count: number;
    service_rejected_revenue: number;
    service_pending_count: number;
    service_approved_count: number;
    
    total_revenue: number;
}

export interface AdminAnalytics {
    categories: CategoryStats[];
    grand_total: {
        // Nick stats
        nick_total_count: number;
        nick_total_revenue: number;
        nick_total_commission: number;
        
        // Nick theo status
        nick_sold_count: number;
        nick_sold_revenue: number;
        nick_deleted_count: number;
        nick_deleted_amount: number;
        nick_returned_count: number;
        nick_returned_amount: number;
        nick_available_count: number;
        nick_available_value: number;
        
        // Service stats
        service_total_count: number;
        service_total_revenue: number;
        service_completed_count: number;
        service_completed_revenue: number;
        service_rejected_count: number;
        service_rejected_revenue: number;
        service_pending_count: number;
        service_approved_count: number;
        
        total_revenue: number;
    };
}

export interface SellerAnalytics {
    categories: SellerCategoryStats[];
    seller_total: {
        // Nick stats
        nick_total_count: number;
        nick_total_revenue: number;
        nick_total_commission: number;
        
        // Nick theo status
        nick_sold_count: number;
        nick_sold_revenue: number;
        nick_deleted_count: number;
        nick_deleted_amount: number;
        nick_returned_count: number;
        nick_returned_amount: number;
        nick_available_count: number;
        nick_available_value: number;
        
        // Service stats
        service_total_count: number;
        service_total_revenue: number;
        service_completed_count: number;
        service_completed_revenue: number;
        service_rejected_count: number;
        service_rejected_revenue: number;
        service_pending_count: number;
        service_approved_count: number;
        
        total_revenue: number;
    };
}

export interface AnalyticsPageProps {
    analytics: AdminAnalytics | SellerAnalytics;
    statDate: string;
    isAdmin: boolean;
    availableDates: string[];
}