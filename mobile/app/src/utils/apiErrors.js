export function getApiErrorMessage(error, fallback = "Incearca din nou.") {
  const data = error?.response?.data;

  if (!data) {
    return fallback;
  }

  if (typeof data.message === "string" && data.message.trim()) {
    return data.message;
  }

  if (data.errors && typeof data.errors === "object") {
    const first = Object.values(data.errors)[0];
    if (Array.isArray(first) && typeof first[0] === "string" && first[0].trim()) {
      return first[0];
    }

    if (typeof first === "string" && first.trim()) {
      return first;
    }
  }

  return fallback;
}

export function getNetworkErrorMessage(error) {
  if (error?.response) {
    return null;
  }

  if (error?.code === "ECONNABORTED") {
    return "Cererea a expirat. Incearca din nou.";
  }

  return "Nu se poate conecta la server. Verifica reteaua si incearca din nou.";
}