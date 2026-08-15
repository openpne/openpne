export interface FriendMember {
    id: number;
    name: string;
    imageUrl: string | null; // null → Avatar renders the neutral initial badge
    avatarColor: string | null;
    isAi: boolean;
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
