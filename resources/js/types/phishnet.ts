export type SetlistRow = {
    showid: number;
    showdate: string;
    permalink: string;
    venue: string;
    city: string;
    state: string;
    country: string;
    artistid: number;
    set: string;
    song: string;
    slug: string;
    trans_mark: string;
    setlistnotes?: string;
};

export type JamChartSong = {
    slug: string;
    song: string;
};

export type JamChartEntry = {
    showdate: string;
    permalink?: string;
    venue?: string;
    city?: string;
    state?: string;
    tracktime?: string;
    jamchart_description?: string;
};

export type Venue = {
    venueid: number;
    venuename: string;
    city: string;
    state: string;
    country: string;
};

export type VenueShow = {
    showdate: string;
    permalink?: string;
    tourname?: string;
    artistid: number;
};

export type ShowYear = {
    showyear: string;
};
