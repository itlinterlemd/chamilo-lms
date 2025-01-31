

export function useFileUtils() {

  const isImage = (fileData) => {
    return isFile(fileData) && fileData.resourceNode.resourceFile.image
  }

  const isVideo = (fileData) => {
    return isFile(fileData) && fileData.resourceNode.resourceFile.video
  }

  const isAudio = (fileData) => {
    const mimeType = fileData.resourceNode.resourceFile.mimeType
    const isAudio = mimeType.split("/")[0].toLowerCase() === "audio"
    return isFile(fileData) && isAudio
  }

  const isHtml = (fileData) => {
    const mimeType = fileData.resourceNode.resourceFile.mimeType
    const isHtml = mimeType.split("/")[1].toLowerCase() === "html"
    return isFile(fileData) && isHtml
  }

  const isFile = (fileData) => {
    return fileData.resourceNode && fileData.resourceNode.resourceFile
  }

  return {
    isFile,
    isImage,
    isVideo,
    isAudio,
    isHtml,
  }
}
