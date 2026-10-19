export function parseInvoicesResponse(payload) {
  if (Array.isArray(payload)) {
    return {
      invoices: payload,
      summary: null,
    };
  }

  return {
    invoices: Array.isArray(payload?.invoices) ? payload.invoices : [],
    summary: payload?.summary ?? null,
    statistics: payload?.statistics ?? null,
  };
}

export function formatInvoiceMonth(month, periodStart) {
  const source = periodStart || (month ? `${month}-01` : null);
  if (!source) return "Luna necunoscuta";

  const date = new Date(`${String(source).slice(0, 10)}T12:00:00`);
  if (Number.isNaN(date.getTime())) return month || "-";

  const label = date.toLocaleDateString("ro-RO", { month: "long", year: "numeric" });
  return label.charAt(0).toUpperCase() + label.slice(1);
}

export function formatInvoicePeriod(invoice) {
  if (invoice?.period_start && invoice?.period_end) {
    const from = formatShortDate(invoice.period_start);
    const to = formatShortDate(invoice.period_end);
    return `${from} – ${to}`;
  }

  return formatInvoiceMonth(invoice?.month, invoice?.period_start);
}

export function invoiceFilename(contentDisposition, invoice) {
  const match = String(contentDisposition || "").match(/filename="?([^";]+)"?/i);
  const fallback = invoice?.invoice_number
    ? `${invoice.invoice_number}.pdf`
    : `factura-${invoice?.id ?? "vcharge"}.pdf`;
  const filename = match?.[1] || fallback;

  return filename.replace(/[^a-zA-Z0-9._-]/g, "-").toLowerCase();
}

export async function downloadInvoiceFile(apiClient, invoice, { Sharing, File, Paths, Alert, getApiErrorMessage }) {
  const response = await apiClient.get(`/invoices/${invoice.id}/download`, {
    responseType: "arraybuffer",
  });

  const filename = invoiceFilename(response.headers?.["content-disposition"], invoice);
  const file = new File(Paths.cache, filename.endsWith(".pdf") ? filename : `${filename}.pdf`);
  const bytes = response.data instanceof ArrayBuffer
    ? new Uint8Array(response.data)
    : response.data;

  if (typeof file.write === "function") {
    file.write(bytes);
  } else if (typeof file.create === "function") {
    await file.create();
    file.write(bytes);
  } else {
    throw new Error("Nu pot salva fisierul PDF pe dispozitiv.");
  }

  if (!(await Sharing.isAvailableAsync())) {
    Alert.alert("Factura", "Factura PDF a fost salvata local, dar partajarea nu este disponibila pe acest dispozitiv.");
    return;
  }

  await Sharing.shareAsync(file.uri, {
    mimeType: "application/pdf",
    dialogTitle: "Factura V CHARGE",
    UTI: "com.adobe.pdf",
  });
}

function formatShortDate(value) {
  const date = new Date(`${String(value).slice(0, 10)}T12:00:00`);
  if (Number.isNaN(date.getTime())) return value || "-";
  return date.toLocaleDateString("ro-RO", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}
