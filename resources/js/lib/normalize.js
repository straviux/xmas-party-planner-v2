/**
 * Shared text normalizer for search: lowercase, accents stripped,
 * extra spaces collapsed. Mirrors the matching approach used on the
 * entry page and on the server.
 */
export function normalizeText(value) {
    return (value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

export default normalizeText;
