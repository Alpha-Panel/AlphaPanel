export function useDomainMode() {
    function domainModeLabel(mode: string): string {
        const map: Record<string, string> = {
            subdomain: 'Subdomain',
            addon: 'Addon Domain',
            wildcard_subdomain: 'Wildcard Subdomain',
            wildcard_catchall: 'Wildcard Catch-All',
        }
        return map[mode] ?? mode
    }

    return { domainModeLabel }
}
