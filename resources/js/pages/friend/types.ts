export interface FriendMember {
    id: number;
    name: string;
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
