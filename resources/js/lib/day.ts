export const DAY_MS = 86_400_000;

/** ISO dates compare lexicographically, so days only matter for sliders. */
export const dayFromIsoDate = (date: string): number =>
    Math.round(Date.parse(date) / DAY_MS);

export const isoDateFromDay = (day: number): string =>
    new Date(day * DAY_MS).toISOString().slice(0, 10);
