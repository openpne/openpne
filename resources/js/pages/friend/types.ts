export interface FriendMember {
    id: number;
    name: string;
    imageUrl: string | null; // null → Avatar renders the neutral initial badge
}

export interface PaginatedFriends {
    data: FriendMember[];
    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
    };
}
