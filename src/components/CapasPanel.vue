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
				A refusal belongs where the person typed — a rename's or an add's,
				both of which are typed in this panel. `error` in App.vue renders
				in the map column and only while no record is open, so a rename
				refused with the sidebar open would have said nothing at all.
			-->
			<NcNoteCard v-if="error !== ''" type="error">
				{{ error }}
			</NcNoteCard>

			<!--
				`info`, never `error` or `warning`: a Category that took the neutral
				because the spares ran out was created correctly, and ADR-0017 says
				in as many words that it must not read as a failure. Hue only groups
				Categories into families; the pictogram identifies them (ADR-0013),
				so this Category is doing what that ADR says all of them do anyway.
			-->
			<NcNoteCard v-if="notice !== ''" type="info">
				{{ notice }}
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
				<!--
					Where the «quitar» control would otherwise be, and said here rather
					than at save time (ADR-0016): every other row in this panel has one,
					so without a sentence a person spends their time trying and then
					wonders whether they did it wrong. The server refuses these too — but
					that refusal is the backstop for a request the panel did not make,
					not the way anybody is meant to find out.

					Static, not derived from a flag on the row: this block IS the
					territorial division (CONTEXT.md puts one toggle per division beside
					the tree rather than inside it), so there is nothing here to test.
				-->
				<p class="territorio-capas__hint">
					La división territorial no se puede quitar: todo sector y toda unidad
					vecinal se guarda en ella.
				</p>
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
					<!--
						The swatch IS the control (#46). A clinic reaches for the mark it
						wants to change, and the row already carries three other buttons —
						a fourth would make the Category's own name the smallest thing in
						it. `@click.stop.prevent` for the reason the checkbox beside it
						stops a click: this sits inside a <summary>, where any click folds
						the branch.
					-->
					<button class="territorio-capas__swatch"
						type="button"
						:style="{ background: category.color }"
						:title="`Elegir pictograma de «${category.label}»`"
						:aria-label="`Elegir pictograma de «${category.label}»`"
						@click.stop.prevent="startPicking(category)">
						<NcIconSvgWrapper :svg="glyphFor(category.slug, category.glyph)" :size="11" />
					</button>
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

							`division_territorial` has no «+» on purpose, and not because it
							was forgotten: this loop runs over the `treeOf` filter, which
							leaves that Category out (it is drawn as the toggles above, not as
							a branch), and a territorial division is drawn on the map rather
							than typed into a field — nothing else may be filed under a Sector
							(CONTEXT.md). The route is deliberately left open rather than made
							to refuse: the `addSubcategory` docblock in App.vue says what a
							row posted there would look like, and why nothing here makes one.
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
						<!--
							Removing is offered on every Category the tree draws (#47).
							«División territorial» is not one of them — `treeOf` leaves it
							out, and the hint above the tree says why in the block where it
							IS drawn.

							Asking happens in the row itself, the two-step inline shape
							`FeatureDetail` uses for retiring a record and never a
							`confirm()`, which blocks the page (ADR-0016).
						-->
						<button v-if="confirming !== keyOf(category)"
							class="territorio-capas__remove"
							type="button"
							:title="`Quitar «${category.label}»`"
							:aria-label="`Quitar «${category.label}»`"
							@click.stop.prevent="startRemove(category)">
							<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
								<path fill="currentColor" :d="TRASH" />
							</svg>
						</button>
						<span v-else class="territorio-capas__confirm" @click.stop>
							<NcButton variant="error" @click.stop.prevent="commitRemove(category)">
								Quitar
							</NcButton>
							<NcButton variant="tertiary" @click.stop.prevent="cancelRemove">
								Cancelar
							</NcButton>
						</span>
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
							<button v-if="confirming !== keyOf(category, subcategory)"
								class="territorio-capas__remove"
								type="button"
								:title="`Quitar «${subcategory.label}»`"
								:aria-label="`Quitar «${subcategory.label}»`"
								@click.stop.prevent="startRemove(category, subcategory)">
								<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
									<path fill="currentColor" :d="TRASH" />
								</svg>
							</button>
							<span v-else class="territorio-capas__confirm">
								<NcButton variant="error"
									@click.stop.prevent="commitRemove(category, subcategory)">
									Quitar
								</NcButton>
								<NcButton variant="tertiary" @click.stop.prevent="cancelRemove">
									Cancelar
								</NcButton>
							</span>
						</template>
					</div>
				</div>
			</details>

			<!--
				The one control in this panel that belongs to no row, so it sits
				under the tree rather than in a summary (#45). Only a name is asked
				for: the slug is derived by the server and the colour is ASSIGNED
				from a vetted list, because its constraints cannot be satisfied by
				eye (ADR-0017) — which is why no colour input appears here or
				anywhere else.
			-->
			<div class="territorio-capas__new">
				<template v-if="editing === NEW_CATEGORY">
					<!--
						Chosen while the Category is being named, not after it exists:
						«a pictogram can be chosen when adding a Category» (#46). It shows
						what has been picked so far, on the neutral — the colour is
						ASSIGNED by the server and is not known until the row exists
						(ADR-0017), and the mark is the half a person is answering.

						`@mousedown.prevent` keeps the focus in the field beside it, whose
						`@blur` cancels the whole add: without it, reaching for the
						pictogram would throw away the name already typed.
					-->
					<button class="territorio-capas__swatch"
						type="button"
						:style="{ background: NEUTRAL }"
						title="Elegir pictograma de la nueva categoría"
						aria-label="Elegir pictograma de la nueva categoría"
						@mousedown.prevent
						@click.stop.prevent="startPicking(null)">
						<NcIconSvgWrapper :svg="glyphFor(null, draftGlyph)" :size="11" />
					</button>
					<input ref="editor"
						v-model="draftLabel"
						class="territorio-capas__input"
						:maxlength="LABEL_LENGTH"
						placeholder="Nueva categoría"
						aria-label="Nombre de la nueva categoría"
						@keydown.stop.enter.prevent="commitCategory"
						@keydown.esc="cancel"
						@blur="cancel">
				</template>
				<NcButton v-else variant="tertiary" @click="startAddCategory">
					Agregar categoría
				</NcButton>
			</div>

			<!--
				One picker for every Category, in one place, rather than a grid
				folded into each of the eleven rows: only one can be open at a time
				— the same rule `editing` already holds for the name fields — and
				eleven copies of 215 marks is eleven copies of the same markup.
				It sits beside «Agregar categoría» because that is the one flow
				where the mark and the name are answered together.

				Previewed at the size the MAP PIN draws, which is what ADR-0017 asks
				and why `PIN_GLYPH_SIZE` is imported rather than typed: a mark judged
				at the 11px of the legend can dissolve at the size it is used, and
				that is exactly what happened to the seeded eleven before #37.

				ponytail: all 215 are mounted at once, and each one sanitises its own
				markup inside NcIconSvgWrapper — so opening this costs 215 of those,
				once, while it is open. Scrolled rather than searched, too: the names
				are Maki's and they are in English, so a box to type «cancha» into
				would need a vocabulary this repo does not have. Render on scroll, or
				give the marks Spanish keywords, when either is actually in the way.
			-->
			<div v-if="picking !== null" ref="picker" class="territorio-capas__picker">
				<p class="territorio-capas__picker-title">
					Pictograma de «{{ picking.label }}»
				</p>
				<div class="territorio-capas__glyphs" :style="{ '--territorio-pin': `${PIN_DOT_SIZE}px` }">
					<!--
						The way back from a mark picked in a hurry that turns out to mean
						something else — which is worse than no mark, because it asserts.
						The Category goes back to the neutral fallback (ADR-0017).
					-->
					<button type="button"
						class="territorio-capas__glyph"
						:style="{ background: NEUTRAL }"
						title="Sin pictograma"
						aria-label="Sin pictograma"
						@mousedown.prevent
						@click="choose('')">
						<NcIconSvgWrapper :svg="glyphFor(null, null)" :size="PIN_GLYPH_SIZE" />
					</button>
					<button v-for="mark in CATALOGUE"
						:key="mark"
						type="button"
						class="territorio-capas__glyph"
						:style="{ background: picking.color }"
						:title="mark"
						:aria-label="mark"
						@mousedown.prevent
						@click="choose(mark)">
						<NcIconSvgWrapper :svg="glyphFor(null, mark)" :size="PIN_GLYPH_SIZE" />
					</button>
				</div>
			</div>
		</div>
	</details>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { CATALOGUE, glyphFor } from '../glyphs.js'
import { categoryState, isFiltered, isSubcategoryVisible } from '../filters.js'
import { PIN_DOT_SIZE, PIN_GLYPH_SIZE } from '../marks.js'
import { DEFAULT_SECTOR_COLOUR, divisionsOf, treeOf } from '../taxonomy.js'

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

/** mdi-trash-can-outline, inlined beside the other two for the same reason. */
const TRASH = 'M9 3v1H4v2h1v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6h1V4h-5V3H9m0 5h2v9H9V8m4 0h2v9h-2V8z'

export default {
	name: 'CapasPanel',

	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcNoteCard },

	props: {
		taxonomy: { type: Array, required: true },
		filter: { type: Object, required: true },
		/** Why the last rename or add was refused, shown beside the row it came from. */
		error: { type: String, default: '' },
		/** Something worth saying about a change that WORKED — see the note card above. */
		notice: { type: String, default: '' },
	},

	emits: [
		'toggle-category',
		'toggle-subcategory',
		'reset',
		'rename',
		'add-category',
		'add-subcategory',
		'remove',
		'choose-glyph',
		'clear-taxonomy-message',
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
			/**
			 * Which row is asking before it removes, as `keyOf()` names it, or
			 * null (#47). Its own key rather than a mode on `editing`, because
			 * the two render different things in the same row and one variable
			 * holding both would be read in two ways by two `v-if`s.
			 *
			 * They are kept mutually exclusive by hand — `open()` closes this and
			 * `startRemove()` closes that — so a row cannot be a field and a
			 * question at the same time.
			 */
			confirming: null,
			draftLabel: '',
			/**
			 * Which Category the pictogram grid is open for, as
			 * `{ slug, label, color }`, or null. One shape for both cases — the
			 * Category that exists and the one being named — with a null `slug`
			 * meaning "the one that does not exist yet", so `choose()` has one
			 * thing to branch on instead of two pieces of state that can disagree
			 * about which is open.
			 */
			picking: null,
			/**
			 * The mark picked for a Category that has not been created yet. It
			 * cannot be written anywhere until the row exists, so it waits here and
			 * goes up with the label on the POST.
			 */
			draftGlyph: null,
			CATALOGUE,
			PIN_DOT_SIZE,
			PIN_GLYPH_SIZE,
			/** What a not-yet-created Category is previewed on: see the button. */
			NEUTRAL: DEFAULT_SECTOR_COLOUR,
			/*
			 * The key for the new-Category field. A constant and not `keyOf()`,
			 * because that one is built from a row's id and this field has no row
			 * — and it cannot collide with one: those are `c<id>`, `s<id>` and
			 * `n<id>`.
			 */
			NEW_CATEGORY: 'new-category',
			PENCIL,
			PLUS,
			TRASH,
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
		 * Open the pictogram grid for one Category, or close it again (#46).
		 *
		 * `null` means the Category being named below, which is the one case where
		 * the grid and a name field are open at once: they are two halves of the
		 * same answer. For every other Category the name field closes, because the
		 * grid stands where it would be read from.
		 *
		 * Toggles on the same Category, so the swatch that opened it closes it —
		 * there is no other way back from a grid somebody opened by mistake, and a
		 * «Cerrar» button would be a second control saying what the first already
		 * says.
		 */
		startPicking(category) {
			this.$emit('clear-taxonomy-message')
			if (category !== null) {
				this.editing = null
			}
			const slug = category?.slug ?? null
			if (this.picking?.slug === slug) {
				this.picking = null

				return
			}
			this.picking = category === null
				? { slug: null, label: 'la nueva categoría', color: DEFAULT_SECTOR_COLOUR }
				: { slug: category.slug, label: category.label, color: category.color }
			// The grid is rendered under the tree and the panel scrolls, so a
			// Category near the top would open a picker nobody can see.
			this.$nextTick(() => this.$refs.picker?.scrollIntoView({ block: 'nearest' }))
		},

		/**
		 * Take the mark, or clear it with `''`.
		 *
		 * A Category that exists is written straight away — there is nothing to
		 * confirm, and the swatch it was picked from shows the answer. One that
		 * does not exist yet keeps it in the draft until the name is committed.
		 */
		choose(glyph) {
			const target = this.picking
			this.picking = null
			if (target.slug === null) {
				this.draftGlyph = glyph === '' ? null : glyph

				return
			}
			this.$emit('choose-glyph', { categorySlug: target.slug, glyph })
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
			this.$emit('clear-taxonomy-message')
			this.confirming = null
			this.picking = null
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

		/**
		 * Start naming a Category the seed does not have (#45).
		 *
		 * Nothing to open first, unlike `startAdd()`: this field is not inside any
		 * `<details>` branch, so there is no folded row it could be hidden in.
		 */
		startAddCategory() {
			this.draftGlyph = null
			this.open(this.NEW_CATEGORY, '')
		},

		/**
		 * The name and the mark: the app derives the slug and assigns the colour
		 * (ADR-0017), and the pictogram is the one of the three a person answers.
		 * `''` when nothing was picked — the Category draws the neutral fallback,
		 * which is what #45 already shipped.
		 */
		commitCategory() {
			const label = this.draftLabel
			const glyph = this.draftGlyph ?? ''
			this.editing = null
			this.picking = null
			this.draftGlyph = null
			this.$emit('add-category', { label, glyph })
		},

		/** Only the name goes up; the server derives the slug (#40). */
		commitAdd(category) {
			const label = this.draftLabel
			this.editing = null
			this.$emit('add-subcategory', { categorySlug: category.slug, label })
		},

		/**
		 * Ask before removing, in the row itself (#47).
		 *
		 * The same two-step inline shape retiring a record uses, and never a
		 * `confirm()`: ADR-0016 says so in as many words, and a browser dialog
		 * blocks the page. The last refusal is cleared on the way, for the reason
		 * `open()` gives — the note card names no row, so one left over from
		 * something else reads as a refusal of this.
		 */
		startRemove(category, subcategory = null) {
			this.$emit('clear-taxonomy-message')
			this.editing = null
			this.confirming = this.keyOf(category, subcategory)
		},

		commitRemove(category, subcategory = null) {
			this.confirming = null
			this.$emit('remove', {
				categorySlug: category.slug,
				subcategorySlug: subcategory?.slug ?? null,
			})
		},

		cancelRemove() {
			this.$emit('clear-taxonomy-message')
			this.confirming = null
		},

		cancel() {
			this.$emit('clear-taxonomy-message')
			this.editing = null
			// The grid is only ever open beside a field when the two are halves of
			// one answer, so abandoning the field abandons the mark with it.
			this.picking = null
			this.draftGlyph = null
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
	/* It is a <button> since #46 — the control that opens the pictogram grid —
	   and a button brings a border, a padding and a background of its own that
	   would hide the Category colour this element exists to show. */
	border: none;
	padding: 0;
	cursor: pointer;
	/* White, fed to NcIconSvgWrapper as `currentColor` rather than forced onto
	   the svg: its own `.icon-vue[data-v-…] svg { fill: currentColor }` ties with
	   a scoped `:deep(svg)` rule at specificity (0,2,1), the library's stylesheet
	   loaded last, and these glyphs rendered in the theme's dark text colour for
	   as long as the rule claimed otherwise (#37). See FeatureList.vue. */
	color: #fff;
}

.territorio-capas__picker {
	padding: 4px 12px 12px;
}

.territorio-capas__picker-title {
	font-weight: bold;
	padding-block: 6px;
}

.territorio-capas__glyphs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	/* The panel itself is capped at 45% of the column and scrolls; a grid of 215
	   marks inside it would push everything else out of reach, so it scrolls on
	   its own. */
	max-height: 200px;
	overflow-y: auto;
}

.territorio-capas__glyph {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	/* The pin, exactly: the dot from `PIN_DOT_SIZE` through the custom property
	   the grid writes, and the mark from `PIN_GLYPH_SIZE` on the wrapper itself.
	   ADR-0017 — a mark judged at legend size can fail at the size it is used. */
	width: var(--territorio-pin);
	height: var(--territorio-pin);
	border-radius: 50%;
	border: none;
	padding: 0;
	flex: 0 0 auto;
	cursor: pointer;
	/* White, fed as `currentColor`, for the reason the swatch above gives. */
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

/* Under the tree and inside the panel body, so it takes the same inset the
   Category rows do and reads as the end of the list rather than as chrome. Flex
   so the field, when it replaces the button, takes the row's full width the way
   every other field in this panel does. */
.territorio-capas__new {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 4px;
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
.territorio-capas__add,
.territorio-capas__remove {
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
.territorio-capas__add:focus-visible,
.territorio-capas__remove:hover,
.territorio-capas__remove:focus-visible {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

/* The theme's readable foreground red, which flips with the theme — the same
   one FeatureDetail's «Retirar del mapa» wears, because it is the same step in
   the same two-step shape. Only on hover and focus: eleven red bins down the
   panel would read as eleven warnings. */
.territorio-capas__remove:hover,
.territorio-capas__remove:focus-visible {
	color: var(--color-error-text);
}

/* The pair of buttons that replaces the bin while the row is asking. Shrinks
   rather than pushing the Category name out of the row, which is what makes the
   thing being removed still readable while it is being confirmed. */
.territorio-capas__confirm {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	flex: 0 1 auto;
	min-width: 0;
}

/* Tertiary ink and small, like the hint text in the sidebar: it explains the
   absence of a control rather than announcing anything, so it must not read as
   a warning. */
.territorio-capas__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 4px 0 0;
}
</style>
