import api from './api';

const parseFilenameFromHeader = (disposition, fallbackFilename) => {
  if (!disposition) return fallbackFilename;

  const utfMatch = disposition.match(/filename\*=UTF-8''([^;]+)/i);
  if (utfMatch?.[1]) {
    try {
      return decodeURIComponent(utfMatch[1].replace(/["']/g, '').trim());
    } catch {
      // Fall through to standard filename match.
    }
  }

  const asciiMatch = disposition.match(/filename="?([^";]+)"?/i);
  if (asciiMatch?.[1]) {
    return asciiMatch[1].trim();
  }

  return fallbackFilename;
};

const triggerBlobDownload = (blobData, filename) => {
  const blob = blobData instanceof Blob ? blobData : new Blob([blobData]);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
};

export const parseBlobErrorMessage = async (error, fallbackMessage) => {
  const blob = error?.response?.data;
  if (blob instanceof Blob) {
    try {
      const text = await blob.text();
      const parsed = JSON.parse(text);
      if (parsed?.message) {
        return parsed.message;
      }
    } catch {
      // Ignore malformed JSON blob.
    }
  }

  return error?.response?.data?.message || fallbackMessage;
};

export const extractImportErrors = (error, fallbackMessage) => {
  const errorsObj = error?.response?.data?.errors;
  if (errorsObj && typeof errorsObj === 'object') {
    const flattened = Object.values(errorsObj).flat().filter(Boolean);
    if (flattened.length > 0) {
      return flattened.map((item) => String(item));
    }
  }

  const msg = error?.response?.data?.message;
  return [msg ? String(msg) : fallbackMessage];
};

export default {
  async downloadTemplate(type, fallbackFilename = 'template.xlsx') {
    const response = await api.get(`/excel/template/${type}`, {
      responseType: 'blob',
    });

    const filename = parseFilenameFromHeader(
      response.headers?.['content-disposition'],
      fallbackFilename
    );
    triggerBlobDownload(response.data, filename);
  },

  async exportData(type, params = {}, fallbackFilename = 'export.xlsx') {
    const cleanParams = Object.fromEntries(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')
    );

    const response = await api.get(`/excel/export/${type}`, {
      params: cleanParams,
      responseType: 'blob',
    });

    const filename = parseFilenameFromHeader(
      response.headers?.['content-disposition'],
      fallbackFilename
    );
    triggerBlobDownload(response.data, filename);
  },

  async importFile(type, file) {
    const formData = new FormData();
    formData.append('excel_file', file);

    const response = await api.post(`/excel/import/${type}`, formData);
    return response.data;
  },
};
