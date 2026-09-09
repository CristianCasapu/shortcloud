import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { ShortLink, UserConfig } from './types'

const base = () => generateOcsUrl('apps/shortcloud/api/v1')

/** The human message of an OCS error, or a generic one. */
export function errorMessage(error: unknown, fallback: string): string {
	const e = error as { response?: { data?: { ocs?: { meta?: { message?: string } } } }, message?: string }
	return e?.response?.data?.ocs?.meta?.message || fallback
}

export async function fetchConfig(): Promise<UserConfig> {
	const { data } = await axios.get(`${base()}/config`)
	return data.ocs.data as UserConfig
}

export async function listLinks(search = '', all = false): Promise<ShortLink[]> {
	const { data } = await axios.get(`${base()}/links`, { params: { search, all: all ? 1 : 0 } })
	return data.ocs.data.links as ShortLink[]
}

export async function linksForShare(shareId: string | number): Promise<ShortLink[]> {
	const { data } = await axios.get(`${base()}/share/${encodeURIComponent(String(shareId))}`)
	return data.ocs.data.links as ShortLink[]
}

export interface CreatePayload {
	target?: string
	shareId?: string | number
	domain?: string
	slug?: string
	title?: string
}

export async function createLink(payload: CreatePayload): Promise<ShortLink> {
	const { data } = await axios.post(`${base()}/links`, payload)
	return data.ocs.data as ShortLink
}

export interface UpdatePayload {
	slug?: string
	domain?: string
	target?: string
	title?: string
	status?: 'active' | 'paused'
}

export async function updateLink(id: number, payload: UpdatePayload): Promise<ShortLink> {
	const { data } = await axios.put(`${base()}/links/${id}`, payload)
	return data.ocs.data as ShortLink
}

export async function deleteLink(id: number): Promise<void> {
	await axios.delete(`${base()}/links/${id}`)
}

// ---- administration

export async function adminGet(): Promise<any> {
	const { data } = await axios.get(`${base()}/admin/settings`)
	return data.ocs.data
}

export async function adminSave(settings: Record<string, unknown>): Promise<any> {
	const { data } = await axios.put(`${base()}/admin/settings`, { settings })
	return data.ocs.data
}

export async function adminInstallRewrite(): Promise<any> {
	const { data } = await axios.post(`${base()}/admin/rewrite`)
	return data.ocs.data
}

export async function adminRemoveRewrite(): Promise<any> {
	const { data } = await axios.delete(`${base()}/admin/rewrite`)
	return data.ocs.data
}

export async function adminSetPrettyUrls(enabled: boolean): Promise<any> {
	const { data } = await axios.put(`${base()}/admin/pretty-urls`, { enabled })
	return data.ocs.data
}

/** Probes a short domain from the browser: true when the rewrite rule answers. */
export async function probe(pingUrl: string): Promise<boolean> {
	try {
		const response = await fetch(pingUrl, { cache: 'no-store', credentials: 'omit', mode: 'cors' })
		if (!response.ok) {
			return false
		}
		const body = await response.json()
		return body?.shortcloud === true
	} catch {
		return false
	}
}

export async function copyText(text: string): Promise<boolean> {
	try {
		await navigator.clipboard.writeText(text)
		return true
	} catch {
		return false
	}
}
