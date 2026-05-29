export interface BlockMember {
    id: number;
    name: string;
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
