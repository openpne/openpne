export interface BlockMember {
    id: number;
    name: string;
    imageUrl: string | null; // null → Avatar renders the neutral initial badge
}

export interface PaginatedBlocks {
    data: BlockMember[];
    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
    };
}
