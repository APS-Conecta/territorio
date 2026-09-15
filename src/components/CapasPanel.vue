<template>
	<!--
		Lives inside the Datos column, not in its own navigation pane. A fourth
		column takes a fixed 300px from a screen that already has to fit a record
		list, a map and a sidebar — and the map, which is the point of the app,
		ends up the narrowest of them.

		Collapsing is a native <details>: no open/closed state to hold, no
		aria-expanded to keep in sync, and it works before the JS loads.
	-->
	<details class="territorio-capas" open>
		<summary class="territorio-capas__summary">
			Capas
			<!--
				Counts what is hidden, so it is gated on that and not on
				`anythingHidden` — which is `isFiltered()`, and is also true for a
				typed search. Searching with nothing ticked off rendered
				"0 ocultas", a badge announcing its own irrelevance.

				"Ver todo" below stays on `anythingHidden`: a search DOES need
				clearing, so there the wider question is the right one.
			-->
			<span v-if="filter.hidden.size > 0" class="territorio-capas__badge">
				{{ filter.hidden.size }} ocultas
			</span>
		</summary>

		<div class="territorio-capas__body">
			<!--
				A refusal belongs where the person typed. `error` in App.vue renders
				in the map column and only while no record is open, so a rename
				refused with the sidebar open would have said nothing at all.
			-->
			<NcNoteCard v-if="error !== ''" type="error">
				{{ error }}
			</NcNoteCard>

			<NcButton v-if="anythingHidden"
				variant="tertiary"
				class="territorio-capas__reset"
				@click="$emit('reset')">
				Ver todo
			</NcButton>

			<!--
				One toggle per territorial division, beside the Category tree rather
				than inside it (CONTEXT.md). A Sector is a Feature and so carries a
				Subcategory like everything else, but nobody thinks of it as a branch
				of "what this place is about" — it is the division the places sit in.
			-->
			<div v-if="divisions.length > 0" class="territorio-capas__divisions">
				<NcCheckboxRadioSwitch v-for="division in divisions"
					:key="division.id"
					:model-value="isVisible(division.id)"
					@update:model-value="$emit('toggle-subcategory', division.id)">
					{{ division.label }}
				</NcCheckboxRadioSwitch>
			</div>

			<!--
				Generated from the taxonomy, never from a hard-coded list: adding a
				Subcategory row is a data change and has to appear here without
				anyone touching this file.
			-->
			<details v-for="category in tree" :key="category.id" class="territorio-capas__category">
				<summary class="territorio-capas__category-summary">
					<!-- Stops the click reaching <summary>, so ticking the box filters
					     instead of expanding the branch. -->
					<span class="territorio-capas__check" @click.stop>
						<NcCheckboxRadioSwitch :model-value="stateOf(category) !== 'none'"
							:indeterminate="stateOf(category) === 'some'"
							@update:model-value="$emit('toggle-category', category)" />
					</span>
					<span class="territorio-capas__swatch" :style="{ background: category.color }">
						<NcIconSvgWrapper :svg="glyphFor(category.slug)" :size="11" />
					</span>
					<!--
						Renaming happens in place, in the row itself: there is no separate
						"layer" object (CONTEXT.md), this panel IS the Category tree, and
						it is already where a person goes to switch Categories on and off
						(#36). A click is stopped like the checkbox above stops one, and so
						is a space: this input sits inside a <summary>, where both default
						to toggling the branch — typing a two-word name would otherwise
						fold the row being renamed.
					-->
					<input v-if="editing === keyOf(category)"
						ref="editor"
						v-model="draftLabel"
						class="territorio-capas__input"
						:maxlength="LABEL_LENGTH"
						aria-label="Nuevo nombre de la categoría"
						@click.stop
						@keydown.stop.enter.prevent="commit(category)"
						@keydown.esc="cancel"
						@keydown.space.stop
						@blur="cancel">
					<template v-else>
						<span class="territorio-capas__name">{{ category.label }}</span>
						<button class="territorio-capas__rename"
							type="button"
							:title="`Renombrar «${category.label}»`"
							:aria-label="`Renombrar «${category.label}»`"
							@click.stop.prevent="startRename(category)">
							<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
								<path fill="currentColor" :d="PENCIL" />
							</svg>
						</button>
						<!--
							Adding a Subcategory starts where a person is already looking at
							the Category it will sit under (#44). Only a name is asked for:
							a Subcategory has no colour and no pictogram of its own — it is
							drawn with its Category's (ADR-0013) — and the slug is derived by
							the server, never typed.
						-->
						<button class="territorio-capas__add"
							type="button"
							:title="`Agregar subcategoría en «${category.label}»`"
							:aria-label="`Agregar subcategoría en «${category.label}»`"
							@click.stop.prevent="startAdd(category, $event)">
							<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
								<path fill="currentColor" :d="PLUS" />
							</svg>
						</button>
					</template>
				</summary>

				<div class="territorio-capas__children">
					<!--
						The new row is typed where it will appear, at the top of its own
						branch, rather than in a dialog over the panel.
					-->
					<div v-if="editing === addKeyOf(category)" class="territorio-capas__child">
						<input ref="editor"
							v-model="draftLabel"
							class="territorio-capas__input"
							:maxlength="LABEL_LENGTH"
							placeholder="Nueva subcategoría"
							:aria-label="`Nombre de la nueva subcategoría en «${category.label}»`"
							@keydown.stop.enter.prevent="commitAdd(category)"
							@keydown.esc="cancel"
							@blur="cancel">
					</div>
					<div v-for="subcategory in category.subcategories"
						:key="subcategory.id"
						class="territorio-capas__child">
						<input v-if="editing === keyOf(category, subcategory)"
							ref="editor"
							v-model="draftLabel"
							class="territorio-capas__input"
							:maxlength="LABEL_LENGTH"
							aria-label="Nuevo nombre de la subcategoría"
							@keydown.stop.enter.prevent="commit(category, subcategory)"
							@keydown.esc="cancel"
							@blur="cancel">
						<template v-else>
							<NcCheckboxRadioSwitch :model-value="isVisible(subcategory.id)"
								@update:model-value="$emit('toggle-subcategory', subcategory.id)">
								{{ subcategory.label }}
							</NcCheckboxRadioSwitch>
							<button class="territorio-capas__rename"
								type="button"
								:title="`Renombrar «${subcategory.label}»`"
								:aria-label="`Renombrar «${subcategory.label}»`"
								@click.stop.prevent="startRename(category, subcategory)">
								<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
									<path fill="currentColor" :d="PENCIL" />
								</svg>
							</button>
						</template>
					</div>
				</div>
			</details>
		</div>
	</details>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { glyphFor } from '../glyphs.js'
import { categoryState, isFiltered, isSubcategoryVisible } from '../filters.js'
import { divisionsOf, treeOf } from '../taxonomy.js'

/**
 * mdi-pencil-outline, inlined like the empty-state icon in AnalisisView.
 * The Maki set this app already carries is place pictograms — it holds nothing
 * for "edit this".
 */
const PENCIL = 'M14.06 9.02l.92.92L5.92 19H5v-.92l9.06-9.06M17.66 3c-.25 0-.51.1-.7.29'
	+ 'l-1.83 1.83 3.75 3.75 1.83-1.83a.996.996 0 0 0 0-1.41l-2.34-2.34c-.2-.2-.45-.29-.71-.29z'
	+ 'm-3.6 3.19L3 17.25V21h3.75L17.81 9.94l-3.75-3.75z'

/** mdi-plus, inlined beside PENCIL for the same reason. */
const PLUS = 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z'

export default {
	name: 'CapasPanel',

	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcNoteCard },

	props: {
		taxonomy: { type: Array, required: true },
		filter: { type: Object, required: true },
		/** Why the last rename was refused, shown beside the row it came from. */
		error: { type: String, default: '' },
	},

	emits: [
		'toggle-category',
		'toggle-subcategory',
		'reset',
		'rename',
		'add-subcategory',
		'clear-taxonomy-error',
	],

	data() {
		return {
			/**
			 * Which row has a field open, as keyOf() or addKeyOf() names it, or
			 * null. One key rather than a flag per kind of field: exactly one is
			 * open at a time, and two pieces of state that have to agree about
			 * that can stop agreeing.
			 */
			editing: null,
			draftLabel: '',
			PENCIL,
			PLUS,
			// The column is VARCHAR(128) and the server refuses more (TaxonomyService).
			// Capping the field here means the usual way of hitting that limit — a
			// pasted paragraph — never becomes a round trip that ends in a refusal.
			LABEL_LENGTH: 128,
		}
	},

	computed: {
		anythingHidden() {
			return isFiltered(this.filter)
		},

		tree() {
			return treeOf(this.taxonomy)
		},

		divisions() {
			return divisionsOf(this.taxonomy)
		},
	},

	methods: {
		glyphFor,

		stateOf(category) {
			return categoryState(this.filter, category)
		},

		isVisible(subcategoryId) {
			return isSubcategoryVisible(this.filter, subcategoryId)
		},

		/**
		 * Which row is open for renaming.
		 *
		 * By id and not by slug: a Subcategory slug is unique only within its
		 * Category — "otro" is in nearly all of them — so a slug alone would put
		 * every «Otro» in the tree into edit mode at once.
		 */
		keyOf(category, subcategory = null) {
			return subcategory === null ? `c${category.id}` : `s${subcategory.id}`
		},

		/** The field for a Subcategory that does not exist yet, under this Category. */
		addKeyOf(category) {
			return `n${category.id}`
		},

		startRename(category, subcategory = null) {
			this.open(this.keyOf(category, subcategory), (subcategory ?? category).label)
		},

		/**
		 * Start naming a new Subcategory under this Category (#44).
		 *
		 * The branch is opened first, and from the event rather than through a
		 * ref: the field is rendered inside `<details>`, and the pencil beside
		 * this control already stops the click that would otherwise have opened
		 * it — so on a folded branch the field would be in the DOM and nowhere on
		 * screen. `open` is a native property of the element and nothing here
		 * binds it, so there is no second copy of the state to keep in step.
		 */
		startAdd(category, event) {
			event.target.closest('.territorio-capas__category').open = true
			this.open(this.addKeyOf(category), '')
		},

		/**
		 * Open one field, with what it starts out saying, and put the cursor in
		 * it — and clear the last refusal on the way.
		 *
		 * The note card sits at the top of the panel body and names no row, so a
		 * refusal left over from something somebody gave up on reads as a refusal
		 * of what they are typing now — with eleven Categories and some fifty
		 * Subcategories in one scrolling panel, there is nothing to tell them
		 * apart by. `rename()` and `addSubcategory()` in App.vue only ever clear
		 * it on success.
		 */
		open(key, label) {
			this.$emit('clear-taxonomy-error')
			this.editing = key
			this.draftLabel = label
			// v-if means the input does not exist until Vue has patched the DOM.
			this.$nextTick(() => {
				const input = this.$refs.editor
				;(Array.isArray(input) ? input[0] : input)?.focus()
			})
		},

		/**
		 * Enter commits, Escape or leaving cancels — the shape renaming a file in
		 * Files already has. Committing on blur would write a half-typed name
		 * every time somebody clicked away to check something.
		 */
		commit(category, subcategory = null) {
			const label = this.draftLabel
			this.editing = null
			this.$emit('rename', {
				categorySlug: category.slug,
				subcategorySlug: subcategory?.slug ?? null,
				label,
			})
		},

		/** Only the name goes up; the server derives the slug (#40). */
		commitAdd(category) {
			const label = this.draftLabel
			this.editing = null
			this.$emit('add-subcategory', { categorySlug: category.slug, label })
		},

		cancel() {
			this.$emit('clear-taxonomy-error')
			this.editing = null
		},
	},
}
</script>

<style scoped>
.territorio-capas {
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
	max-height: 45%;
	overflow-y: auto;
}

.territorio-capas__summary {
	cursor: pointer;
	padding: 10px 12px;
	font-weight: bold;
	position: sticky;
	top: 0;
	background: var(--color-main-background);
	z-index: 1;
}

.territorio-capas__badge {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin-inline-start: 6px;
}

.territorio-capas__body {
	padding: 0 12px 8px;
}

.territorio-capas__reset {
	margin-bottom: 4px;
}

.territorio-capas__divisions {
	padding-bottom: 6px;
	margin-bottom: 6px;
	border-bottom: 1px solid var(--color-border);
}

.territorio-capas__category-summary {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	padding: 2px 0;
}

.territorio-capas__check {
	display: inline-flex;
}

.territorio-capas__swatch {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	/* 16px, not the 12px it was. A Maki glyph is drawn on a 15-unit grid and
	   needs about 12px of mark to hold its detail; this is the legend for the
	   map, so the two have to read as the same pictogram. It stayed at 16/11
	   while #37 took the pin to 26/19 — the legend has its Category name beside
	   it and the pin has nothing. */
	width: 16px;
	height: 16px;
	border-radius: 50%;
	flex: 0 0 auto;
	/* White, fed to NcIconSvgWrapper as `currentColor` rather than forced onto
	   the svg: its own `.icon-vue[data-v-…] svg { fill: currentColor }` ties with
	   a scoped `:deep(svg)` rule at specificity (0,2,1), the library's stylesheet
	   loaded last, and these glyphs rendered in the theme's dark text colour for
	   as long as the rule claimed otherwise (#37). See FeatureList.vue. */
	color: #fff;
}

.territorio-capas__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.territorio-capas__children {
	padding-inline-start: 34px;
}

.territorio-capas__child {
	display: flex;
	align-items: center;
	gap: 6px;
}

/* Takes the width the label had, so the row does not jump when it turns into a
   field and back. */
.territorio-capas__input {
	flex: 1 1 auto;
	min-width: 0;
	margin: 0;
}

/* Always drawn, never on hover alone: a control that appears under the pointer
   is not there for a finger or for a keyboard (the panel's checkboxes are
   already tabbed through). Tertiary ink so eleven of them do not shout over the
   Category names they belong to.

   The «+» that adds a Subcategory is the same control in the same row and wears
   the same treatment, under its own name: `__rename` still means the pencil, and
   the browser gate picks a pencil by that class. */
.territorio-capas__rename,
.territorio-capas__add {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: 24px;
	height: 24px;
	padding: 0;
	border: none;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.territorio-capas__rename:hover,
.territorio-capas__rename:focus-visible,
.territorio-capas__add:hover,
.territorio-capas__add:focus-visible {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
</style>
