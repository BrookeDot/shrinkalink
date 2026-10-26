(function () {
	const { __, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor;
	const { useSelect, useDispatch } = wp.data;
	const { createElement: el, Fragment, useState, useEffect, useRef } =
		wp.element;
	const {
		TextControl,
		CheckboxControl,
		ExternalLink,
		Button,
		__experimentalConfirmDialog: ConfirmDialog,
	} = wp.components;

	function ShrinkalinkPanel() {
		const { postId, status, requested, isSaving } = useSelect((select) => {
			const editor = select("core/editor");

			return {
				postId: editor.getCurrentPostId(),
				status: editor.getCurrentPostAttribute("status"),
				requested:
					editor.getEditedPostAttribute("meta")?._shrinkalink_requested || "",
				isSaving: editor.isSavingPost() && !editor.isAutosavingPost(),
			};
		}, []);
		const { editPost } = useDispatch("core/editor");

		const [shortUrl, setShortUrl] = useState("");
		const [savedCode, setSavedCode] = useState("");
		const [code, setCode] = useState("");
		const [replace, setReplace] = useState(false);
		const [alsoShlink, setAlsoShlink] = useState(true);
		const [busy, setBusy] = useState(false);
		const [confirming, setConfirming] = useState(false);
		const [blocked, setBlocked] = useState(false);
		const [checked, setChecked] = useState(null);

		// update the panel from any route's response
		const applyResponse = (data) => {
			setShortUrl(data.shortUrl);
			setSavedCode(data.shortCode);
			setCode(data.shortCode);
			setBlocked(data.blocked);
			setReplace(false);
			setChecked(null);
		};

		// the requested code when a save started, to tell if the server cleared it
		const requestedAtSave = useRef("");

		// load the link on open, and again after each save, since publishing can create one
		useEffect(() => {
			if (isSaving) {
				requestedAtSave.current = requested;
				return;
			}

			const asked = requestedAtSave.current;
			requestedAtSave.current = "";

			wp.apiFetch({ path: "/shrinkalink/v1/posts/" + postId + "/link" })
				.then((data) => {
					applyResponse(data);

					// the server drops a taken request on save, so say why the field emptied
					const stillRequested = wp.data
						.select("core/editor")
						.getEditedPostAttribute("meta")?._shrinkalink_requested;

					if (asked && !stillRequested && data.shortCode !== asked) {
						wp.data.dispatch("core/notices").createWarningNotice(
							sprintf(
								/* translators: %s: the requested short code */
								__("The short code \"%s\" is already in use, so it was cleared.", "shrinkalink"),
								asked,
							),
							{ type: "snackbar" },
						);
					}
				})
				.catch((error) => console.error("Shrinkalink:", error));
		}, [postId, isSaving]);

		const save = () => {
			setBusy(true);

			wp.apiFetch({
				path: "/shrinkalink/v1/posts/" + postId + "/link",
				method: "POST",
				data: { shortcode: code, replace },
			})
				.then((data) => {
					applyResponse(data);

					if (false === data.oldDeleted) {
						wp.data
							.dispatch("core/notices")
							.createWarningNotice(
								__(
									"New link saved, but the old link could not be deleted from Shlink.",
									"shrinkalink",
								),
								{ type: "snackbar" },
							);
					}
				})
				.catch((error) =>
					wp.data
						.dispatch("core/notices")
						.createErrorNotice(error.message, { type: "snackbar" }),
				)
				.finally(() => setBusy(false));
		};

		// remove the link from this post (and optionally Shlink), blocking it from being recreated on save
		const remove = () => {
			setConfirming(false);
			setBusy(true);

			wp.apiFetch({
				path: "/shrinkalink/v1/posts/" + postId + "/link",
				method: "DELETE",
				data: { shlink: alsoShlink },
			})
				.then(applyResponse)
				.catch((error) =>
					wp.data
						.dispatch("core/notices")
						.createErrorNotice(error.message, { type: "snackbar" }),
				)
				.finally(() => setBusy(false));
		};

		// ask Shlink whether the typed code is free; only runs when the button is clicked
		const check = () => {
			const value = typed;

			setBusy(true);

			wp.apiFetch({
				path: wp.url.addQueryArgs("/shrinkalink/v1/posts/" + postId + "/check", {
					shortcode: value,
				}),
			})
				.then((data) => setChecked({ code: value, ...data }))
				.catch((error) =>
					wp.data
						.dispatch("core/notices")
						.createErrorNotice(error.message, { type: "snackbar" }),
				)
				.finally(() => setBusy(false));
		};

		const changed = savedCode !== "" && code !== savedCode;
		const canCreate = (window.shrinkalinkSettings?.statuses || []).includes(status);

		// the code being typed: the request on drafts, the live field otherwise
		const typed = canCreate ? code : requested;

		// a result only counts for the exact code it was checked for
		const result = checked && checked.code === typed ? checked : null;
		const checkedTaken = result !== null && !result.available;
		const needsCheck =
			typed !== "" && typed !== savedCode && !(result && result.available);

		// the check result in core's alert red or WordPress admin green, a little larger than help text
		const checkHelp = !result
			? null
			: el(
					"span",
					{
						style: {
							color: result.available ? "#007017" : "#cc1818",
							fontSize: "13px",
							fontWeight: 500,
						},
					},
					!result.available
						? __("Already in use. Try another short code.", "shrinkalink")
						: result.shortcode !== typed
							? __("Available, and will be saved as: ", "shrinkalink") +
								result.shortcode
							: __("Available.", "shrinkalink"),
				);

		return el(
			PluginDocumentSettingPanel,
			{
				name: "shrinkalink-panel",
				title: __("Shrinkalink", "shrinkalink"),
			},
			shortUrl
				? el(
						"p",
						{ style: { display: "flex", alignItems: "center", gap: "4px" } },
						el(ExternalLink, { href: shortUrl }, shortUrl),
						!changed &&
							el(Button, {
								icon: "trash",
								label: __("Delete shortlink", "shrinkalink"),
								size: "small",
								isDestructive: true,
								onClick: () => setConfirming(true),
								disabled: busy,
							}),
					)
				: el(
						"p",
						null,
						blocked
							? __("Shortlink deleted. A new one won't be created on save; add a short code below to create one.", "shrinkalink")
							: __("No shortlink yet. One is created when the post is published.", "shrinkalink"),
					),

			el(
				ConfirmDialog,
				{
					isOpen: confirming,
					onConfirm: remove,
					onCancel: () => {
						setConfirming(false);
						setAlsoShlink(true);
					},
					confirmButtonText: __("Delete", "shrinkalink"),
				},
				el(
					"p",
					null,
					__(
						"Remove this shortlink from the post?",
						"shrinkalink",
					),
				),
				el(
					"p",
					{ style: { fontSize: "12px", color: "#757575", marginTop: "-8px" } },
					__("Manually deleted shortlinks won't be recreated on save. A new short code must be added manually.", "shrinkalink"),
				),
				el(CheckboxControl, {
					label: __("Also delete from Shlink", "shrinkalink"),
					help: __(
						"If deleted from Shlink, the link stops working everywhere. This can't be undone.",
						"shrinkalink",
					),
					checked: alsoShlink,
					onChange: setAlsoShlink,
					__nextHasNoMarginBottom: true,
				}),
			),

			// drafts edit the requested code, saved with the post; otherwise the live code
			el(TextControl, {
				label: __("Short code", "shrinkalink"),
				value: canCreate ? code : requested,
				onChange: canCreate
					? setCode
					: (value) => editPost({ meta: { _shrinkalink_requested: value } }),
				help:
					checkHelp ||
					(canCreate
						? null
						: el(
								Fragment,
								null,
								__(
									"Saved with the post, and used for its shortlink when it's published.",
									"shrinkalink",
								),
								el("br"),
								el("br"),
								__(
									"Check availability before using to prevent conflicts. If a conflict occurs, this field will be cleared and a random short code will be used on publish.",
									"shrinkalink",
								),
							)),
				// a taken code gets core's alert red, and is announced as invalid to screen readers
				"aria-invalid": checkedTaken,
				style: checkedTaken
					? { borderColor: "#cc1818", boxShadow: "0 0 0 1px #cc1818" }
					: undefined,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			}),

			changed &&
				el(
					"div",
					{ style: { marginTop: "16px" } },
					el(CheckboxControl, {
						label: __("Delete current link", "shrinkalink"),
						help: __(
							"Removes the current shortlink in addition to creating a new link with the updated short code.",
							"shrinkalink",
						),
						checked: replace,
						onChange: setReplace,
						__nextHasNoMarginBottom: true,
					}),
				),

			// one button: check a typed code first, then create or update with it
			(changed || !savedCode) &&
				el(
					"div",
					{ style: { marginTop: "16px" } },
					needsCheck
						? el(
								Button,
								{
									variant: "secondary",
									onClick: check,
									isBusy: busy,
									disabled: busy,
								},
								__("Check availability", "shrinkalink"),
							)
						: el(
								Button,
								{
									variant: "secondary",
									onClick: save,
									isBusy: busy,
									disabled: busy || !canCreate || checkedTaken,
								},
								savedCode
									? __("Update shortlink", "shrinkalink")
									: __("Create shortlink", "shrinkalink"),
							),
				),
		);
	}

	registerPlugin("shrinkalink", { render: ShrinkalinkPanel });
})();
