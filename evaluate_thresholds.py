#!/usr/bin/env python3
"""
Standalone script to evaluate recognition thresholds using test face images.
Computes cosine similarities, plots ROC curve, and finds EER and best threshold.
"""

import argparse
import os
import sys
from pathlib import Path
import numpy as np
import matplotlib.pyplot as plt
from sklearn.metrics import roc_curve, auc
from insightface.app import FaceAnalysis

# Add src to path to import modules
sys.path.append(str(Path(__file__).parent))

from config import config
from recognition import l2_normalize, cosine_sim

def load_test_embeddings(test_folder):
    """
    Load embeddings from test images organized in subfolders by identity.
    test_folder/identity/image.jpg
    """
    app = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
    app.prepare(ctx_id=-1, det_size=(640, 640))
    
    embeddings = {}
    test_path = Path(test_folder)
    
    for identity_dir in test_path.iterdir():
        if not identity_dir.is_dir():
            continue
        identity = identity_dir.name
        embeddings[identity] = []
        
        for img_file in identity_dir.glob("*.jpg"):
            try:
                img = plt.imread(str(img_file))
                if img.dtype != np.uint8:
                    img = (img * 255).astype(np.uint8)
                
                faces = app.get(img, max_num=1)
                if faces:
                    emb = l2_normalize(faces[0].embedding.astype(np.float32))
                    embeddings[identity].append(emb)
                    print(f"Loaded {identity}: {img_file.name}")
            except Exception as e:
                print(f"Error loading {img_file}: {e}")
    
    return embeddings

def compute_similarity_pairs(embeddings):
    """
    Compute genuine and impostor similarity scores.
    """
    genuine_scores = []
    impostor_scores = []
    
    identities = list(embeddings.keys())
    
    # Genuine pairs (same identity)
    for identity, embs in embeddings.items():
        if len(embs) < 2:
            continue
        for i in range(len(embs)):
            for j in range(i+1, len(embs)):
                sim = cosine_sim(embs[i], embs[j])
                genuine_scores.append(sim)
    
    # Impostor pairs (different identities)
    for i in range(len(identities)):
        for j in range(i+1, len(identities)):
            id1, id2 = identities[i], identities[j]
            for emb1 in embeddings[id1]:
                for emb2 in embeddings[id2]:
                    sim = cosine_sim(emb1, emb2)
                    impostor_scores.append(sim)
    
    return genuine_scores, impostor_scores

def plot_roc_curve(genuine_scores, impostor_scores):
    """
    Plot ROC curve and return EER and best threshold.
    """
    # Combine scores and labels
    scores = genuine_scores + impostor_scores
    labels = [1] * len(genuine_scores) + [0] * len(impostor_scores)
    
    fpr, tpr, thresholds = roc_curve(labels, scores)
    roc_auc = auc(fpr, tpr)
    
    # Find EER (where FPR ≈ 1 - TPR)
    eer_idx = np.argmin(np.abs(fpr - (1 - tpr)))
    eer = (fpr[eer_idx] + (1 - tpr[eer_idx])) / 2
    eer_threshold = thresholds[eer_idx]
    
    # Find best threshold (minimizing FPR + FNR)
    fnr = 1 - tpr
    best_idx = np.argmin(fpr + fnr)
    best_threshold = thresholds[best_idx]
    
    # Plot
    plt.figure(figsize=(8, 6))
    plt.plot(fpr, tpr, color='darkorange', lw=2, label=f'ROC curve (AUC = {roc_auc:.2f})')
    plt.plot([0, 1], [0, 1], color='navy', lw=2, linestyle='--')
    plt.scatter(fpr[eer_idx], tpr[eer_idx], color='red', s=50, label=f'EER = {eer:.3f} at threshold {eer_threshold:.3f}')
    plt.scatter(fpr[best_idx], tpr[best_idx], color='green', s=50, label=f'Best threshold = {best_threshold:.3f}')
    plt.xlim([0.0, 1.0])
    plt.ylim([0.0, 1.05])
    plt.xlabel('False Positive Rate')
    plt.ylabel('True Positive Rate')
    plt.title('Receiver Operating Characteristic (ROC) Curve')
    plt.legend(loc="lower right")
    plt.grid(True)
    plt.show()
    
    return eer, eer_threshold, best_threshold

def main():
    parser = argparse.ArgumentParser(description="Evaluate face recognition thresholds")
    parser.add_argument("test_folder", help="Folder containing test images organized by identity subfolders")
    args = parser.parse_args()
    
    if not Path(args.test_folder).exists():
        print(f"Test folder {args.test_folder} does not exist")
        return
    
    print("Loading test embeddings...")
    embeddings = load_test_embeddings(args.test_folder)
    
    if not embeddings:
        print("No embeddings loaded")
        return
    
    print(f"Loaded embeddings for {len(embeddings)} identities")
    total_images = sum(len(embs) for embs in embeddings.values())
    print(f"Total images: {total_images}")
    
    print("Computing similarity pairs...")
    genuine_scores, impostor_scores = compute_similarity_pairs(embeddings)
    
    print(f"Genuine pairs: {len(genuine_scores)}")
    print(f"Impostor pairs: {len(impostor_scores)}")
    
    if not genuine_scores or not impostor_scores:
        print("Not enough pairs for evaluation")
        return
    
    print("Plotting ROC curve...")
    eer, eer_threshold, best_threshold = plot_roc_curve(genuine_scores, impostor_scores)
    
    print(".3f")
    print(".3f")
    print(".3f")
    print(".3f")

if __name__ == "__main__":
    main()