jQuery(function ($) {
  "use strict";

  const $profileForm = $("#hst-profile-form");
  if ($profileForm.length) {
    $profileForm.on("submit", function (event) {
      event.preventDefault();

      const data = {
        first_name: $.trim($profileForm.find('[name="first_name"]').val()),
        last_name: $.trim($profileForm.find('[name="last_name"]').val()),
      };

      // Teachers can also edit their biography from their profile.
      const $bio = $profileForm.find('[name="teacher_bio"]');
      if ($bio.length) {
        data.teacher_bio = $bio.val() || "";
      }

      if (!data.first_name || !data.last_name) {
        HST.toast("نام و نام خانوادگی را کامل وارد کنید.", "error");
        return;
      }

      HST.request({
        action: "hst_update_own_profile",
        data,
        successMessage: true,
        onSuccess(response) {
          const d = response && response.data ? response.data : {};
          if (d.display_name) {
            $(".hst-profile-hero__name").text(d.display_name);
            $(".hst-header__name").text(d.display_name);
          }
        },
      });
    });
  }

  const $passwordForm = $("#hst-password-form");
  if ($passwordForm.length) {
    $passwordForm.on("submit", function (event) {
      event.preventDefault();

      const data = {
        new_password: $passwordForm.find('[name="new_password"]').val(),
        confirm_password: $passwordForm.find('[name="confirm_password"]').val(),
      };

      if (!data.new_password || !data.confirm_password) {
        HST.toast("لطفاً رمز عبور جدید و تکرار آن را وارد کنید.", "error");
        return;
      }

      if (data.new_password.length < 8) {
        HST.toast("رمز عبور جدید باید حداقل ۸ کاراکتر باشد.", "error");
        return;
      }

      if (data.new_password !== data.confirm_password) {
        HST.toast("تکرار رمز عبور با رمز جدید یکسان نیست.", "error");
        return;
      }

      HST.request({
        action: "hst_update_own_password",
        data,
        trigger: $passwordForm.find('button[type="submit"]').get(0),
        successMessage: true,
        onSuccess() {
          $passwordForm[0].reset();
        },
      });
    });
  }

  const $modal = $("[data-hst-avatar-modal]");
  if (!$modal.length) return;

  const $file = $("#hst-avatar-file");
  const $previewWrap = $(".hst-avatar-preview");
  const $preview = $("[data-hst-avatar-preview]");
  const $cropper = $("[data-hst-avatar-cropper]");
  const $save = $("[data-hst-avatar-save]");

  let image = null;
  let imageLoaded = false;
  let imageUrl = "";
  let state = {
    zoom: 1,
    offsetX: 0,
    offsetY: 0,
  };

  const pointers = new Map();
  let gesture = {
    mode: null,
    startX: 0,
    startY: 0,
    originX: 0,
    originY: 0,
    startDistance: 0,
    startZoom: 1,
    centerX: 0,
    centerY: 0,
    originCenterX: 0,
    originCenterY: 0,
  };

  function openAvatarModalFor(targetId) {
    const safeTargetId = String(targetId || "").trim();
    if (!safeTargetId) return;

    resetAvatarEditor();
    $modal.data("targetUser", safeTargetId);
    $modal.removeAttr("hidden").addClass("is-active").attr("aria-hidden", "false");
    $("body").addClass("hst-modal-open");
  }

  $(document).on("click", "[data-hst-avatar-open-for]", function () {
    openAvatarModalFor($(this).attr("data-hst-avatar-open-for"));
  });

  function closeAvatarModal() {
    $modal.attr("hidden", "hidden").removeClass("is-active").attr("aria-hidden", "true");
    $("body").removeClass("hst-modal-open");
    pointers.clear();
    $previewWrap.removeClass("is-dragging");
  }

  function getFrameSize() {
    const rect = $previewWrap[0]?.getBoundingClientRect();
    return rect?.width ? rect.width : 224;
  }

  function getCropBox(frameSize) {
    const cropFrame = $previewWrap.find("span")[0];
    if (!cropFrame) {
      return { x: 0, y: 0, size: frameSize };
    }

    const frameRect = $previewWrap[0].getBoundingClientRect();
    const cropFrameRect = cropFrame.getBoundingClientRect();
    const size = Math.min(cropFrameRect.width, cropFrameRect.height) || frameSize;

    return {
      x: cropFrameRect.left - frameRect.left,
      y: cropFrameRect.top - frameRect.top,
      size,
    };
  }

  function getImageMetrics(frameSize, zoom) {
    if (!image) return null;

    const baseScale = Math.max(frameSize / image.width, frameSize / image.height);
    const safeZoom = Math.max(1, Math.min(4, Number(zoom || 1)));
    const scale = baseScale * safeZoom;
    const width = image.width * scale;
    const height = image.height * scale;
    const maxOffsetX = Math.max(0, (width - frameSize) / 2);
    const maxOffsetY = Math.max(0, (height - frameSize) / 2);

    return { baseScale, scale, width, height, maxOffsetX, maxOffsetY };
  }

  function clampState() {
    if (!imageLoaded || !image) return;

    state.zoom = Math.max(1, Math.min(4, Number(state.zoom || 1)));
    const frameSize = getFrameSize();
    const metrics = getImageMetrics(frameSize, state.zoom);
    if (!metrics) return;

    state.offsetX = Math.max(-metrics.maxOffsetX, Math.min(metrics.maxOffsetX, state.offsetX));
    state.offsetY = Math.max(-metrics.maxOffsetY, Math.min(metrics.maxOffsetY, state.offsetY));
  }

  function refreshPreviewStyle() {
    if (!imageLoaded || !image) return;

    clampState();

    const frameSize = getFrameSize();
    const metrics = getImageMetrics(frameSize, state.zoom);
    if (!metrics) return;

    $preview.css({
      width: `${metrics.width}px`,
      height: `${metrics.height}px`,
      transform: `translate(-50%, -50%) translate(${state.offsetX}px, ${state.offsetY}px)`,
    });
  }

  function resetCropper() {
    state = {
      zoom: 1,
      offsetX: 0,
      offsetY: 0,
    };
    pointers.clear();
    refreshPreviewStyle();
  }

  function resetAvatarEditor() {
    if (imageUrl) {
      URL.revokeObjectURL(imageUrl);
    }

    image = null;
    imageLoaded = false;
    imageUrl = "";
    state = {
      zoom: 1,
      offsetX: 0,
      offsetY: 0,
    };
    pointers.clear();
    gesture.mode = null;
    $file.val("");
    $preview.removeAttr("src style");
    $previewWrap.removeClass("is-dragging");
    $cropper.attr("hidden", "hidden");
    $save.prop("disabled", true).removeClass("is-loading");
  }

  function loadImage(file) {
    if (!file) return;

    if (!/^image\/(png|jpe?g|webp)$/i.test(file.type)) {
      HST.toast("فرمت تصویر باید JPG، PNG یا WEBP باشد.", "error");
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      HST.toast("حجم فایل انتخابی نباید بیشتر از ۵ مگابایت باشد.", "error");
      return;
    }

    if (imageUrl) {
      URL.revokeObjectURL(imageUrl);
    }

    imageUrl = URL.createObjectURL(file);
    image = new Image();
    image.onload = function () {
      imageLoaded = true;
      $preview.attr("src", imageUrl);
      $cropper.removeAttr("hidden");
      $save.prop("disabled", false);
      resetCropper();
    };
    image.onerror = function () {
      HST.toast("امکان خواندن تصویر انتخاب‌شده وجود ندارد.", "error");
      imageLoaded = false;
      $save.prop("disabled", true);
    };
    image.src = imageUrl;
  }

  function buildCroppedImage() {
    if (!imageLoaded || !image) return null;

    const outputSize = 512;
    const frameSize = getFrameSize();
    const cropBox = getCropBox(frameSize);
    const metrics = getImageMetrics(frameSize, state.zoom);
    if (!metrics) return null;

    const drawX = (frameSize - metrics.width) / 2 + state.offsetX;
    const drawY = (frameSize - metrics.height) / 2 + state.offsetY;
    const outputScale = outputSize / cropBox.size;

    const canvas = document.createElement("canvas");
    canvas.width = outputSize;
    canvas.height = outputSize;

    const ctx = canvas.getContext("2d", { alpha: false });
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = "high";
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, outputSize, outputSize);

    ctx.drawImage(
      image,
      (drawX - cropBox.x) * outputScale,
      (drawY - cropBox.y) * outputScale,
      metrics.width * outputScale,
      metrics.height * outputScale,
    );

    return canvas.toDataURL("image/jpeg", 0.9);
  }

  function getPointerList() {
    return Array.from(pointers.values());
  }

  function distance(a, b) {
    return Math.hypot(a.x - b.x, a.y - b.y);
  }

  function center(a, b) {
    return {
      x: (a.x + b.x) / 2,
      y: (a.y + b.y) / 2,
    };
  }

  function startGesture() {
    const list = getPointerList();

    if (list.length >= 2) {
      const c = center(list[0], list[1]);
      gesture = {
        mode: "pinch",
        startX: 0,
        startY: 0,
        originX: state.offsetX,
        originY: state.offsetY,
        startDistance: distance(list[0], list[1]) || 1,
        startZoom: state.zoom,
        centerX: c.x,
        centerY: c.y,
        originCenterX: c.x,
        originCenterY: c.y,
      };
      return;
    }

    if (list.length === 1) {
      gesture = {
        mode: "drag",
        startX: list[0].x,
        startY: list[0].y,
        originX: state.offsetX,
        originY: state.offsetY,
        startDistance: 0,
        startZoom: state.zoom,
        centerX: 0,
        centerY: 0,
        originCenterX: 0,
        originCenterY: 0,
      };
    }
  }

  function updateGesture() {
    if (!imageLoaded) return;

    const list = getPointerList();

    if (list.length >= 2) {
      if (gesture.mode !== "pinch") {
        startGesture();
      }

      const currentCenter = center(list[0], list[1]);
      const currentDistance = distance(list[0], list[1]) || 1;
      const ratio = currentDistance / (gesture.startDistance || currentDistance);

      state.zoom = Math.max(1, Math.min(4, gesture.startZoom * ratio));
      state.offsetX = gesture.originX + (currentCenter.x - gesture.originCenterX);
      state.offsetY = gesture.originY + (currentCenter.y - gesture.originCenterY);
      refreshPreviewStyle();
      return;
    }

    if (list.length === 1 && gesture.mode === "drag") {
      state.offsetX = gesture.originX + (list[0].x - gesture.startX);
      state.offsetY = gesture.originY + (list[0].y - gesture.startY);
      refreshPreviewStyle();
    }
  }

  $(document).on("click", "[data-hst-avatar-close]", closeAvatarModal);
  $(document).on("click", "[data-hst-avatar-choose]", function () {
    $file.trigger("click");
  });

  $file.on("change", function () {
    loadImage(this.files && this.files[0]);
  });

  $previewWrap.on("pointerdown", function (event) {
    if (!imageLoaded) return;

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    $previewWrap.addClass("is-dragging");
    this.setPointerCapture?.(event.pointerId);
    startGesture();
    event.preventDefault();
  });

  $previewWrap.on("pointermove", function (event) {
    if (!pointers.has(event.pointerId)) return;

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    updateGesture();
    event.preventDefault();
  });

  $previewWrap.on("pointerup pointercancel pointerleave", function (event) {
    if (!pointers.has(event.pointerId)) return;

    pointers.delete(event.pointerId);
    this.releasePointerCapture?.(event.pointerId);

    if (!pointers.size) {
      gesture.mode = null;
      $previewWrap.removeClass("is-dragging");
    } else {
      startGesture();
    }
  });

  $save.on("click", async function () {
    const cropped = buildCroppedImage();
    if (!cropped) {
      HST.toast("ابتدا یک تصویر انتخاب کنید.", "error");
      return;
    }

    const $button = $(this);
    $button.prop("disabled", true);

    closeAvatarModal();

    const targetUser = $modal.data("targetUser");
    const requestData = { image: cropped };
    if (targetUser) requestData.target_user_id = targetUser;

    const response = await HST.request({
      action: "hst_update_profile_avatar",
      data: requestData,
      successMessage: true,
      onSuccess(response) {
        const url = response?.data?.avatar_url;
        if (url && targetUser) {
          const avatarSelector = `[data-hst-avatar-open-for="${targetUser}"]`;

          $(avatarSelector).each(function () {
            const $avatar = $(this);
            let $image = $avatar.find(`[data-hst-avatar-img-for="${targetUser}"]`);

            if (!$image.length) {
              $image = $("<img>", {
                alt: "تصویر پروفایل",
                loading: "lazy",
                "data-hst-avatar-img-for": targetUser,
              }).prependTo($avatar);
            }

            $image.attr("src", url).prop("hidden", false).show();
            $avatar
              .find(`[data-hst-avatar-placeholder-for="${targetUser}"]`)
              .remove();
            $avatar.removeClass("hst-user-avatar--placeholder");
          });

          $(`[data-student-id="${targetUser}"]`).attr("data-avatar-url", url);

          const $headerAvatar = $(`[data-hst-avatar-header-for="${targetUser}"]`);
          if ($headerAvatar.length) {
            if (response?.data?.pending) {
              $headerAvatar.addClass("is-pending");
              if (!$headerAvatar.find("[data-hst-avatar-pending-badge]").length) {
                $("<span>", {
                  class: "hst-header-avatar__pending-badge",
                  "data-hst-avatar-pending-badge": "",
                  title: "تصویر در انتظار تأیید مدیر است",
                  text: "در انتظار تأیید",
                }).appendTo($headerAvatar);
              }
            } else {
              $headerAvatar.removeClass("is-pending");
              $headerAvatar.find("[data-hst-avatar-pending-badge]").remove();
            }
          }
        }
      },
    });

    if (!response?.success && imageLoaded) {
      $button.prop("disabled", false);
    }
  });

  $(window).on("resize", refreshPreviewStyle);

  $(document).on("keydown", function (event) {
    if (event.key === "Escape" && !$modal.is("[hidden]")) {
      closeAvatarModal();
    }
  });
});
