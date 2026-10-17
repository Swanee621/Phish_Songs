export type StatsBounds = {
    /** ISO date of the first recorded visit, null before any exist. */
    from: string | null;
    to: string | null;
    visits: number;
    /** Visits whose address could not be placed in a city. */
    unresolved: number;
};

export type PlacePoint = {
    id: number;
    lat: number;
    lng: number;
    count: number;
    city: string | null;
    region: string | null;
    country_code: string | null;
    /** ISO times of the latest visits in the range, newest first. */
    recent: string[];
};

/** One page view. The place fields are null when the address was unplaced. */
export type VisitRow = {
    id: number;
    visited_at: string;
    path: string;
    lat: number | null;
    lng: number | null;
    city: string | null;
    region: string | null;
    country_code: string | null;
};

export type VisitsPage = {
    data: VisitRow[];
    meta: {
        page: number;
        perPage: number;
        total: number;
        lastPage: number;
    };
};
