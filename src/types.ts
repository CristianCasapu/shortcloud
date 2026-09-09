export interface Domain {
	host: string
	prefix: string
	scheme: string
	default: boolean
}

export interface UserConfig {
	domains: Domain[]
	canCreate: boolean
	isAdmin: boolean
	paused: boolean
	externalTargets: 'none' | 'list' | 'any'
	allowedHosts: string[]
	slugLength: number
}

export interface ShortLink {
	id: number
	userId: string
	domain: string
	slug: string
	target: string
	shareId: string | null
	title: string | null
	status: 'active' | 'paused' | 'gone'
	hits: number
	lastHit: number | null
	createdAt: number
	updatedAt: number
	shortUrl: string
}

export function domainLabel(d: Domain): string {
	return `${d.scheme}://${d.host}/${d.prefix ? d.prefix + '/' : ''}`
}
