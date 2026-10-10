export type StatsBounds = {
    /** ISO date of the first recorded visit, null before any exist. */
    from: string | null;
    to: string | null;
    visits: number;
    visitors: number;
    /** Visits whose address could not be placed in a city. */
    unresolved: number;
};

export type HeatPoint = {
    lat: number;
    lng: number;
    count: number;
    visitors: number;
    city: string | null;
    region: string | null;
    country_code: string | null;
};

export type TravellerStop = {
    lat: number;
    lng: number;
    city: string | null;
    region: string | null;
    country_code: string | null;
    first_at: string;
    last_at: string;
    visits: number;
};

export type Traveller = {
    visitor_id: string;
    first_seen: string;
    last_seen: string;
    stops: TravellerStop[];
};
