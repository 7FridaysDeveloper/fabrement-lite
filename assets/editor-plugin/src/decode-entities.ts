/**
 * Server error messages arrive HTML-escaped: PHP escapes the values it puts into an exception
 * (esc_html, as the WordPress.org review requires). React prints them as text, so without this a
 * label like "Author's name" reads "Author&#039;s name". A textarea's value is the decoded text
 * and never runs markup, which is also what @wordpress/html-entities does.
 */
export function decodeEntities(text: string): string {
    if (!text.includes('&')) return text;
    const area = document.createElement('textarea');
    area.innerHTML = text;
    return area.value;
}
