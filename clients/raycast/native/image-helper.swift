import AppKit
import Foundation

// This process reads images only when the user invokes Paste Image or selects a file.
// It neither records clipboard history nor prints image data.
let maximumBytes = 1_048_576

func output(_ value: [String: String]) -> Never {
    let data = try! JSONSerialization.data(withJSONObject: value, options: [.sortedKeys])
    FileHandle.standardOutput.write(data)
    exit(0)
}

func render(_ image: NSImage, width: Int, height: Int) -> NSBitmapImageRep? {
    guard let bitmap = NSBitmapImageRep(
        bitmapDataPlanes: nil, pixelsWide: width, pixelsHigh: height,
        bitsPerSample: 8, samplesPerPixel: 4, hasAlpha: true,
        isPlanar: false, colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0
    ), let context = NSGraphicsContext(bitmapImageRep: bitmap) else { return nil }
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = context
    context.imageInterpolation = .high
    NSColor.white.setFill()
    NSRect(x: 0, y: 0, width: width, height: height).fill()
    image.draw(in: NSRect(x: 0, y: 0, width: width, height: height), from: .zero, operation: .sourceOver, fraction: 1)
    NSGraphicsContext.restoreGraphicsState()
    return bitmap
}

let arguments = CommandLine.arguments
guard arguments.count >= 3 else { output(["error": "Missing image source."]) }
let destination = arguments.last!
let image: NSImage?
if arguments[1] == "--file", arguments.count == 4 {
    let url = URL(fileURLWithPath: arguments[2])
    let size = (try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize) ?? 0
    guard size > 0, size <= 40 * 1024 * 1024 else { output(["error": "Choose an image smaller than 40 MiB."]) }
    image = NSImage(contentsOf: url)
} else if arguments[1] == "--clipboard", arguments.count == 3 {
    let clipboard = NSPasteboard.general
    if let files = clipboard.readObjects(forClasses: [NSURL.self], options: [.urlReadingFileURLsOnly: true]) as? [URL],
       let file = files.first {
        let size = (try? file.resourceValues(forKeys: [.fileSizeKey]).fileSize) ?? 0
        guard size > 0, size <= 40 * 1024 * 1024 else { output(["error": "Choose an image smaller than 40 MiB."]) }
        image = NSImage(contentsOf: file)
    } else {
        image = NSImage(pasteboard: clipboard)
    }
} else {
    output(["error": "Unknown image source."])
}
guard let image else { output([:]) }
let representation = image.representations.max { $0.pixelsWide * $0.pixelsHigh < $1.pixelsWide * $1.pixelsHigh }
let originalWidth = representation?.pixelsWide ?? Int(image.size.width)
let originalHeight = representation?.pixelsHigh ?? Int(image.size.height)
guard originalWidth > 0, originalHeight > 0, originalWidth <= 32_768, originalHeight <= 32_768,
      originalWidth * originalHeight <= 100_000_000 else { output(["error": "Image dimensions are too large or invalid."]) }
var scale = min(1.0, 4096.0 / Double(max(originalWidth, originalHeight)))

for _ in 0..<10 {
    let width = max(1, Int(Double(originalWidth) * scale))
    let height = max(1, Int(Double(originalHeight) * scale))
    guard let bitmap = render(image, width: width, height: height) else { output(["error": "Could not decode this image."]) }
    for quality in [0.90, 0.80, 0.68, 0.54] {
        if let data = bitmap.representation(using: .jpeg, properties: [.compressionFactor: quality]), data.count <= maximumBytes {
            let path = destination + ".jpg"
            do {
                try data.write(to: URL(fileURLWithPath: path), options: .atomic)
                try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: path)
                output(["path": path, "mimeType": "image/jpeg"])
            } catch { output(["error": "Could not save the image locally."]) }
        }
    }
    scale *= 0.75
}
output(["error": "Could not reduce this image to 1 MiB."])
