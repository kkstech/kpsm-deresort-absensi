import os
import math
from PIL import Image, ImageDraw, ImageFont, ImageFilter

def create_radial_gradient(width, height, center_color, edge_color):
    img = Image.new("RGBA", (width, height), edge_color)
    cx, cy = width / 2.0, height / 2.0
    max_radius = math.hypot(cx, cy)
    
    # We can create a 256-step radial map
    gradient = Image.new("RGBA", (width, height))
    draw = ImageDraw.Draw(gradient)
    
    steps = 120
    for i in range(steps, -1, -1):
        ratio = i / float(steps)
        r = max_radius * ratio
        # interpolate colors
        cur_color = tuple(
            int(center_color[j] + (edge_color[j] - center_color[j]) * (1.0 - ratio ** 1.3))
            for j in range(4)
        )
        draw.ellipse([cx - r, cy - r, cx + r, cy + r], fill=cur_color)
        
    return gradient

def draw_emblem(draw, center_x, center_y, size, font_path):
    """Draw the Deresort emblem (Badge + D + Lime Accent)"""
    bx = center_x - size // 2
    by = center_y - size // 2
    corner_radius = int(size * 0.28)
    
    # Badge background (solid dark resort green)
    badge_color = (20, 52, 37, 255)
    border_color = (42, 106, 78, 255)
    
    # Outer border
    draw.rounded_rectangle(
        [bx - 4, by - 4, bx + size + 4, by + size + 4],
        radius=corner_radius + 4,
        fill=border_color
    )
    # Badge body
    draw.rounded_rectangle(
        [bx, by, bx + size, by + size],
        radius=corner_radius,
        fill=badge_color
    )
    
    # Draw letter 'D'
    d_font_size = int(size * 0.62)
    font_bold = ImageFont.truetype(font_path, d_font_size)
    
    # Measure 'D'
    bbox = font_bold.getbbox("D")
    w = bbox[2] - bbox[0]
    h = bbox[3] - bbox[1]
    
    # Center 'D' slightly adjusting for visual center
    dx = center_x - (bbox[0] + bbox[2]) / 2.0 - int(size * 0.02)
    dy = center_y - (bbox[1] + bbox[3]) / 2.0
    
    draw.text((dx, dy), "D", font=font_bold, fill=(255, 255, 255, 255))
    
    # Vibrant Lime accent dot / diamond on top-right of the D
    dot_radius = int(size * 0.075)
    dot_cx = int(center_x + size * 0.28)
    dot_cy = int(center_y - size * 0.25)
    
    lime_accent = (217, 242, 95, 255) # #D9F25F
    draw.ellipse(
        [dot_cx - dot_radius, dot_cy - dot_radius, dot_cx + dot_radius, dot_cy + dot_radius],
        fill=lime_accent
    )

def generate_assets():
    font_bold_path = "mobile/assets/fonts/plus-jakarta-800.ttf"
    font_med_path = "mobile/assets/fonts/plus-jakarta-600.ttf"
    
    c_primary = (23, 61, 43, 255)    # #173D2B
    c_center = (32, 84, 59, 255)     # #20543B
    c_edge = (14, 38, 27, 255)       # #0E261B
    
    # 1. ICON (1024 x 1024)
    print("Generating icon.png...")
    icon = create_radial_gradient(1024, 1024, c_center, c_edge)
    draw_icon = ImageDraw.Draw(icon)
    draw_emblem(draw_icon, 512, 512, 620, font_bold_path)
    icon.save("mobile/assets/icon.png", "PNG", optimize=True)
    
    # 2. ADAPTIVE ICON FOREGROUND (1024 x 1024) with transparent background
    print("Generating adaptive-icon.png...")
    adaptive = Image.new("RGBA", (1024, 1024), (0, 0, 0, 0))
    draw_adaptive = ImageDraw.Draw(adaptive)
    # Inside 560px for safe area masking (720px max circle)
    draw_emblem(draw_adaptive, 512, 512, 540, font_bold_path)
    adaptive.save("mobile/assets/adaptive-icon.png", "PNG", optimize=True)
    
    # 3. SPLASH SCREEN (1284 x 2778)
    print("Generating splash.png...")
    splash = Image.new("RGBA", (1284, 2778), c_primary)
    
    # Subtle radial soft glow in upper-middle area
    glow = Image.new("RGBA", (1284, 2778), (0, 0, 0, 0))
    glow_draw = ImageDraw.Draw(glow)
    cx, cy = 642, 1180
    r = 500
    for i in range(50, 0, -1):
        ratio = i / 50.0
        cur_r = r * ratio
        alpha = int(45 * (1.0 - ratio))
        glow_draw.ellipse(
            [cx - cur_r, cy - cur_r, cx + cur_r, cy + cur_r],
            fill=(35, 95, 67, alpha)
        )
    splash = Image.alpha_composite(splash, glow)
    draw_splash = ImageDraw.Draw(splash)
    
    # Emblem at center
    emblem_size = 320
    emblem_cy = 1120
    draw_emblem(draw_splash, cx, emblem_cy, emblem_size, font_bold_path)
    
    # Wordmark "DERESORT"
    font_brand = ImageFont.truetype(font_bold_path, 72)
    brand_text = "DERESORT"
    # Manual tracking / letter spacing
    spacing = 14
    chars = list(brand_text)
    total_w = sum(font_brand.getbbox(c)[2] - font_brand.getbbox(c)[0] for c in chars) + spacing * (len(chars) - 1)
    cur_x = cx - total_w // 2
    text_y = emblem_cy + emblem_size // 2 + 54
    for c in chars:
        cw = font_brand.getbbox(c)[2] - font_brand.getbbox(c)[0]
        draw_splash.text((cur_x, text_y), c, font=font_brand, fill=(255, 255, 255, 255))
        cur_x += cw + spacing
        
    # Subtitle "ABSENSI & SHIFT"
    font_sub = ImageFont.truetype(font_med_path, 28)
    sub_text = "ABSENSI & SHIFT"
    sub_spacing = 8
    sub_chars = list(sub_text)
    sub_total_w = sum(font_sub.getbbox(c)[2] - font_sub.getbbox(c)[0] for c in sub_chars) + sub_spacing * (len(sub_chars) - 1)
    cur_x = cx - sub_total_w // 2
    sub_y = text_y + 90
    lime_accent = (217, 242, 95, 255)
    for c in sub_chars:
        cw = font_sub.getbbox(c)[2] - font_sub.getbbox(c)[0]
        draw_splash.text((cur_x, sub_y), c, font=font_sub, fill=lime_accent)
        cur_x += cw + sub_spacing
        
    # Footer tag
    font_footer = ImageFont.truetype(font_med_path, 22)
    footer_text = "SISTEM KEHADIRAN ON-PREMISE"
    f_spacing = 5
    f_chars = list(footer_text)
    f_total_w = sum(font_footer.getbbox(c)[2] - font_footer.getbbox(c)[0] for c in f_chars) + f_spacing * (len(f_chars) - 1)
    cur_x = cx - f_total_w // 2
    footer_y = 2560
    c_footer = (142, 178, 158, 200)
    for c in f_chars:
        cw = font_footer.getbbox(c)[2] - font_footer.getbbox(c)[0]
        draw_splash.text((cur_x, footer_y), c, font=font_footer, fill=c_footer)
        cur_x += cw + f_spacing

    splash.save("mobile/assets/splash.png", "PNG", optimize=True)
    
    # 4. FAVICON (192 x 192)
    print("Generating favicon.png...")
    favicon = icon.resize((192, 192), Image.Resampling.LANCZOS)
    favicon.save("mobile/assets/favicon.png", "PNG", optimize=True)
    
    print("All assets generated successfully!")

if __name__ == "__main__":
    generate_assets()
