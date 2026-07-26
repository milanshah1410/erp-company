/** One phrase entry: Hindi + Gujarati for an English source phrase. */
export type PhraseEntry = { hi?: string; gu?: string }
export type PhraseFile = Record<string, PhraseEntry>
